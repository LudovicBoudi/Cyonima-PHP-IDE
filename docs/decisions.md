# Décisions d'architecture (ADR)

Décisions actées le 19/11/2026. Chaque ADR est contraignante jusqu'à
révision explicite.

---

## ADR-001 — Périmètre des bindings M1 : stack GNOME complet

**Statut :** accepté

**Décision :** le jalon M1 couvre les quatre bibliothèques dès le départ :
GTK4, libadwaita, GtkSourceView5 et VTE.

**Contexte :** libadwaita définit la structure même des applications
(`AdwApplication`, `AdwApplicationWindow`, `AdwHeaderBar`, `AdwNavigationView`,
`AdwPreferencesWindow`, `AdwToast`). Le rétrofitter après coup impose de
réécrire tout le code applicatif. À l'inverse, GtkSourceView5 (éditeur de code,
coloration PHP) et VTE (terminal) sont des widgets feuilles, mais ils sont
indispensables à la cible IDE et leur surface est faible.

**Surface mesurée (typelib) :** Gtk 4.0 = 889 types, Adw 1 = 178,
GtkSource 5 = 130, Vte 3.91 = 41, GObject 2.0 = 312.

**Conséquences :**
- M1 plus long (≈ 6–10 semaines au lieu de 4–8), première fenêtre plus tardive.
- Le sous-ensemble curaté reste nécessaire : on ne génère pas 1 550 types,
  on en expose ~120–150 au départ.
- Flatpak `org.gnome.Platform` fournit les quatre bibliothèques : cohérent
  avec ADR-005.

---

## ADR-002 — Deux couches : bindings bruts + façade orientée objet

**Statut :** accepté

**Décision :**
1. `Cyonima\Gtk\Raw\*` — généré mécaniquement depuis les `.typelib`, miroir
   fidèle du C, sans sucre syntaxique.
2. `Cyonima\UI\*` — façade OO écrite à la main : `new Label('…')`,
   `$button->onClicked(...)`, avec `->raw()` comme échappatoire.

**Contexte :** un générateur qui produit directement une API « jolie » doit
encoder des choix de design et devient impossible à régénérer quand GTK évolue.
La couche brute reste testable par différentiation et régénérable ; la couche
facade est le seul endroit où l'on met du goût.

**Conséquences :**
- Deux fois plus de code à maintenir, mais la partie générée ne s'édite jamais
  à la main.
- Le backend devient remplaçable (extension C, autre toolkit) sans toucher au
  code applicatif.
- `->raw()` doit rester documenté et utilisé dans les tests.

---

## ADR-003 — Modèle d'UI impératif retained-mode

**Statut :** accepté

**Décision :** API impérative orientée objet, widgets persistants, plus des
helpers de composition (`VBox::of()`, `Form::row()`, `Binding::bind()`).
Aucun moteur de re-render, aucun diffing.

**Contexte :** GTK est *stateful*. Les modèles déclaratifs avec re-render
(style React) exigent de diffe un arbre de widgets dont l'état réel vit dans le
C ; c'est un puits sans fond. Un IDE est par nature plein d'état impératif
(onglets, curseurs, éditeurs, arbres de fichiers). GNOME Builder, l'application
la plus proche de notre cible, suit exactement ce modèle.

**Conséquences :**
- La responsabilité de la cohérence de l'UI revient au code applicatif.
- Les helpers doivent rester minces : pas de framework caché.
- Les tests d'UI se font par émission de signaux synthétiques
  (`g_signal_emit_by_name`), déjà validé dans le spike.

---

## ADR-004 — PHP 8.3 minimum

**Statut :** accepté

**Décision :** plancher PHP 8.3. CI sur 8.3, 8.4 et 8.5. Interdiction de la
syntaxe 8.4+ dans les bibliothèques `Raw`/`UI`/runtime.

**Contexte :** PHP 8.1 est EOL depuis décembre 2025, 8.2 est en support
sécurité seulement, Ubuntu 24.04 livre 8.3 et Debian 13 livre 8.4. Le runtime
des applications produites étant embarqué, le plancher ne concerne que le
développement depuis les sources.

**Conséquences :**
- On peut utiliser les classes `readonly`, les constantes de classe typées,
  `json_validate()`.
- Les callbacks FFI sont validés sur 8.3.6 ; un test dédié doit tourner sur
  chaque version de la CI.
- La doc PHP signale que les callbacks FFI ne sont pas supportés sur toutes
  les plateformes libffi : le support se limite à Linux x86_64/aarch64.

---

## ADR-005 — Flatpak pour l'IDE, AppImage pour les apps produites

**Statut :** accepté

**Décision :**
- **IDE** : Flatpak sur `org.gnome.Platform` (49/50/51 disponibles sur Flathub).
- **Applications produites** : AppImage avec GTK bundlé, plus tard export
  Flatpak optionnel.

**Contexte :** le runtime GNOME contient déjà GTK4, libadwaita, GtkSourceView5
et VTE. Empaqueter soi-même ces quatre bibliothèques est du travail inutile et
une source de bugs de version. Pour les applications livrées à des utilisateurs
non-GNOME (KDE, XFCE, minimal), la version de GTK varie trop (4.6 sur Ubuntu
22.04, 4.8 sur Debian 12, 4.14 sur Ubuntu 24.04) : il faut bundler.

**Conséquences :**
- L'IDE demande des permissions Flatpak larges (`--filesystem=host`,
  `--share=network`, `--socket=session-bus`) : c'est le prix d'un outil de dev,
  GNOME Builder fait de même.
- Les apps produites embarquent ~60–120 Mo de runtime selon les backends
  graphiques ; prévoir une option `--system-gtk` pour les binaires légers.
- Le pipeline de build doit produire des AppImages reproductibles (CI).

---

## Règles techniques issues des mesures

### R1 — Ne jamais enregistrer une closure par événement

Mesures du 19/11/2026 :

| Scénario | Volume | Delta RSS |
|---|---|---|
| Signal GTK, handler connecté une fois | 300 000 appels | +1,75 Mo puis plateau |
| Closure convertie à chaque appel (`qsort`) | 500 000 appels | +99 Mo |
| `g_timeout_add` par événement | 100 000 enregistrements | +44 Mo |
| `g_idle_add` par événement | 100 000 enregistrements | +43 Mo |
| `g_idle_add` + `g_source_remove` immédiat | 100 000 enregistrements | +20 Mo |

La fuite est **par conversion closure → pointeur de fonction** (~200–650 o
selon le chemin), pas par invocation. Conséquences :

- un handler de signal connecté une fois peut être appelé des millions de fois ;
- les timers et idle sources doivent être **enregistrés une seule fois** au
  démarrage, puis dépiler des files PHP ;
- tout code qui appelle `g_timeout_add`/`g_idle_add` dans une boucle est
  interdit ;
- un test de RSS en CI doit couvrir la boucle d'événements sur 10 minutes.

### R2 — Aucune exception ne traverse la frontière C

`Throwing from FFI callbacks is not allowed`. Chaque handler est encapsulé
dans un adaptateur qui capture `Throwable`, le stocke, et le remonte après
retour dans PHP.

### R3 — Toute conversion d'API passe par la couche brute

La façade OO ne déclare jamais de `FFI::cdef` ni ne manipule de `CData` : elle
appelle `Cyonima\Gtk\Raw`. Cela garantit que la validation et la gestion des
références restent centralisées.

---

## Notes d'implémentation issues de la tranche M1

Trois écarts assumés par rapport au miroir strict du C :

1. **`GObject.Object` n'est pas généré sous ce nom** : PHP réserve le nom de
   classe `Object`. `Cyonima\Gtk\Raw\RawObject` sert de racine et porte la
   gestion des références, des signaux et des erreurs de callback.
2. **Les interfaces et structs non générés sont opaques** : ils circulent en
   `\FFI\CData` (ex. `GtkStyleProvider`, `GdkDisplay`). La façade convertit
   explicitement quand nécessaire (`->handle()`).
3. **`Symbols::ensureInitialized()`** est appelé par chaque constructeur généré :
   `gtk_init_check()` doit précéder toute création de widget, sinon segfault.

## Notes d'implémentation issues du runtime (M2)

4. **Un seul tick GLib de 16 ms** : tous les timers applicatifs vivent dans une
   file PHP (`Loop::after`), ce qui rend R1 structurel et non déclaratif.
5. **Le dispatcher de signaux est installé par `Application`** : les signaux
   void deviennent des fibres, les signaux à retour booléen restent synchrones.
   `Cyonima\Gtk\Raw` ne dépend pas du runtime : il expose un point d'injection.
6. **Deux niveaux d'asynchrone** : fibres coopératives pour l'I/O et les
   séquences, sous-processus pour le CPU. Une fibre sans point de suspension
   fige l'UI, c'est assumé et documenté.

### Notes d'implémentation Adw (M3)

7. **`g_application_register()` sans `g_application_run()`** : Adw exige le
   signal `startup` avant de créer une `AdwApplicationWindow`, mais on conserve
   la boucle du runtime. `AdwWindow` utilise `AdwToolbarView` + `AdwHeaderBar`
   (`set_titlebar()` est interdit par libadwaita sur ce type de fenêtre).
8. **Deux namespaces = deux `Symbols`** : un handle libffi par bibliothèque
   (`libgtk-4.so.1`, `libadwaita-1.so.0`), avec `adw_init()` dans le bootstrap
   du namespace Adw.

### Notes d'implémentation GtkSourceView/VTE (M4)

9. **Un cdef par signature de signal** : les callbacks libffi doivent connaître
   le type exact des paramètres, sinon un `gint` arrive en `CData`. Les cdefs
   sont générés à la demande et mis en cache par signature.
10. **Constructeurs explicites plutôt que downcast** : `View::get_buffer()`
    renvoie le type déclaré (`GtkTextBuffer`), pas le type réel. On construit
    donc `GtkSourceBuffer` puis on l'injecte via `View::new_with_buffer()`.
11. **Pont VTE** : `spawn_async` (tableaux + callbacks) reste hors du
    générateur ; `Runtime\Vte` construit `argv` en mémoire C et gère la
    callback de spawn.

### Notes d'implémentation IDE (M5)

12. **Lecture du buffer sans shim C** : `GtkTextIter` est une struct publique de
    80 octets, alignement 8 (vérifié via GObject Introspection). `GtkText`
    déclare un blob aligné de cette taille pour appeler `get_text`.
13. **Déconnexion des signaux à la destruction du wrapper** : un GObject
    parenté survit à son wrapper PHP ; sans `g_signal_handler_disconnect`, la
    trampoline C pointerait vers une closure libérée.
14. **LSP sur stdio** : client JSON-RPC minimal, non bloquant, branché sur le
    tick. Aucune dépendance à un serveur particulier.

15. **Complétion sans widget dédié** : `GtkPopover` + `GtkListBox` + un
    `GtkEventControllerKey` sur l'éditeur. Le popover ne prend pas le focus,
    donc les flèches/Entrée/Échap restent gérées par l'éditeur.

16. **Parsing DBGp** : sur un nœud d'un namespace non par défaut, l'accès
    tableau SimpleXML (`$node['attr']`) ne résout pas les attributs ; il faut
    `$node->attributes()`. Les événements du client Xdebug sont émis avec des
    arguments positionnels (PHP 8 interpréterait un tableau à clés string
    comme des arguments nommés).
17. **Le débogueur n'ajoute aucun binding** : DBGp est du XML sur TCP, hors du
    périmètre du générateur. `XdebugClient` est un composant runtime.

18. **Aucune dépendance PHP externe** : autoloader PSR-4 maison, Composer
    optionnel. Le bundle portable n'embarque ni `vendor/` ni outil de build.
19. **Bundle portable** : archive tar.gz + lanceur `bin/cyonima` (force
    `ffi.enable=1`), `.desktop` et icône SVG. Testé de bout en bout depuis un
    répertoire temporaire.
20. **Flatpak construit et exécuté** : PHP 8.3.14 (archive officielle,
    SHA256 vérifié) et VTE 0.76.0 compilés, app lancée dans le bac à sable.
    Le runtime GNOME ne fournit ni PHP ni VTE. VTE est construit avec
    `-Dlibdir=lib` car `/app/lib64` n'est pas dans le chemin de recherche.
    Xdebug n'est pas inclus et `--filesystem=host` reste nécessaire.
21. **AppImage autonome (56 Mo)** : PHP 8.3.14 et toute la pile GTK
    (GTK4/Adw/GtkSourceView/VTE + 107 dépendances), schémas, loaders et thèmes
    sont embarqués. glibc et les pilotes GPU restent ceux de l'hôte. Preuves :
    `ldd` sans dépendance non résolue, `LD_DEBUG` montrant GTK dans le montage,
    et exécution avec les bibliothèques GTK système masquées dans un namespace
    utilisateur. `mbstring` absent (repli ASCII).

22. **Réponses DBGp corrélées** : chaque requête (`stack_get`, `context_get`,
    `property_get`) porte un `-i` et une callback en attente, indexée par
    `transaction_id`. Sans cela, une réponse `status="break"` serait confondue
    avec un nouvel arrêt.

23. **Activation des rows GTK4** : au clic, `gtk_list_box_activate()` émet
    `row-activated` sur la `GtkListBox`, **pas** `activate` sur la
    `GtkListBoxRow`. Le FileBrowser écoute donc `row-activated` et mappe
    l'index de la row. L'émission manuelle de `activate` masquait le bug dans
    les premiers tests ; un clic X11 réel l'a révélé.
24. **Instances multiples** : `AdwApplication` utilise
    `G_APPLICATION_NON_UNIQUE`. Sans ce drapeau, une seconde instance devient
    « distante » et `gtk_window_set_application()` plante pendant la création
    de fenêtre.

25. **Design inspiré de Visual Studio** : chrome sombre, barre d'activité,
    onglet d'éditeur et barre de statut bleue. Le schéma GtkSourceView `vs-dark`
    est écrit dans `$TMPDIR` puis chargé via `append_search_path()` +
    `force_rescan()`, avec repli `Adwaita-dark`. Tout le chrome est en CSS
    (constante `Theme::CSS`), sans ressource externe.

26. **IntelliSense** : déclenchement débouncé à la frappe, popup ancré au
    curseur via `gtk_text_view_get_cursor_locations()` +
    `gtk_popover_set_pointing_to()`. Repli `PhpIntellisense` sans serveur LSP.
    Après insertion, suppression de 400 ms car `replacePrefix()` émet deux
    signaux `changed`.
27. **Explorateur redimensionnable** : `GtkPaned` horizontal,
    `resize_start_child=false` pour que l'éditeur absorbe les changements de
    taille de fenêtre.

28. **Filtrage local IntelliSense** : tant que le préfixe s'allonge, la liste
    est filtrée sans requête. Le popover est masqué pendant la frappe (focus
    conservé par l'éditeur), puis réaffiché 90 ms après la dernière touche ;
    la surface popover est recréée à la bonne taille (elle ne se redimensionne
    pas quand seul son enfant change). Nouvelle requête uniquement si le préfixe
    est raccourci, change de mot ou si la liste est fermée.

29. **Fenêtre de démarrage façon Visual Studio** : sans argument, l'exécutable
    affiche modèles (GTK4, console, bibliothèque), projets récents et création
    d'environnement. `ProjectFactory` écrit `.cyonima/project.json`, `src/`,
    `assets/`, `LICENSE`, `README.md`, `.gitignore`. L'IDE est ensuite lancé
    dans un processus séparé avec `--project`, qui exporte `CYONIMA_PROJECT` et
    `CYONIMA_HOME`. `--auto` conserve le scénario de test direct.

30. **Licences des projets** : MIT, Apache-2.0, GPL-3.0 ou propriétaire, choisie
    à la création ; le texte est écrit dans `LICENSE` et l'identifiant SPDX dans
    le manifeste. La vue `PROPRIÉTÉS` permet de changer la licence après coup :
    `ProjectFactory::updateLicense()` réécrit `LICENSE` et le manifeste, sans
    toucher au README ni aux assets ; la barre de statut de l'IDE est actualisée.
    Cyonima IDE est distribué sous MIT, en open source : pas de clé d'activation,
    pas de plan payant, pas de vérification réseau. Le répertoire de
    configuration (`CYONIMA_CONFIG_DIR`) ne sert qu'aux projets récents et
    préférences.

31. **Assets de projet** : chaque création génère `assets/icon.svg` et
    `assets/theme.css` ; le panneau ASSETS liste, importe (chemin relatif au
    projet ou absolu) et ouvre les fichiers. `ProjectFactory::removeAsset()`
    couvre la suppression côté runtime. Toute modification rafraîchit la liste
    `assets` du manifeste. Les chemins relatifs sont résolus depuis la racine
    du projet, pas depuis le répertoire courant de l'IDE.

32. **Barre de menus native** : `MenuBar` construit des boutons et des
    `GtkPopover` ancrés. Sans rectangle de visée, GTK place les popovers hors
    écran ; on pointe donc sur une largeur de 320 px pour aligner le menu à
    gauche du bouton. Le survol d'un autre titre bascule immédiatement le menu
    via un `GtkEventControllerMotion` synchrone, sans devoir fermer le menu
    courant. Les actions sont des closures et les raccourcis clavier
    (`Ctrl+S`, `Ctrl+Z`, `Ctrl+A`, `F5`…) passent par le contrôleur de
    l'éditeur.

33. **Concepteur d'interface** : palette + canevas `GtkFixed` + panneau de
    propriétés. Le glisser-déposer utilise `GtkGestureDrag` et
    `gtk_widget_compute_point()` pour convertir les coordonnées palette →
    canevas. Le modèle (`FormDocument`) est sérialisé en JSON et généré en
    `App\Forms\<Nom>Base` + `App\Forms\<Nom>`. Un projet peut contenir
    plusieurs formulaires, listés dans la palette et sauvegardés dans
    `.cyonima/forms/`. Chaque contrôle porte un nom PHP unique et expose des
    événements prédéfinis câblés vers des méthodes `on<Nom><Événement>()`. La
    classe de base est regénérée ; la sous-classe utilisateur est préservée
    pour recevoir les actions. Les types image, vidéo et séparateur utilisent
    `GtkImage`, `GtkVideo` et `GtkSeparator` ; les chemins d’assets sont
    relatifs au projet. Un bouton peut embarquer une image : le code généré
    compose alors un `Box(Image, Label)` passé à `Button::child()`. Le
    sélecteur d’assets est un popover intégré listant `assets/`, avec accès
    optionnel au sélecteur natif `GtkFileDialog`.

34. **Export d'application autonome** : deux cibles officielles.
    `build-project.sh` produit une AppImage en environnement de développement
    et depuis l'IDE installé qui embarque `appimagetool` + `mksquashfs`.
    `build-project-flatpak.sh` génère un manifeste et un `.flatpak` via
    `flatpak-builder`/SDK GNOME. Le bundle portable reste un secours interne.
    L'IDE expose les formats via `ProjectBuilder` et `Process`.

35. **Extensions PHP natives (lot réseau/Linux)** : `ssh2` via libssh2, plus
    `openssl`, `sockets`, `pcntl` et `posix` dans les PHP embarqués AppImage et
    Flatpak. Les extensions sont copiées dans les applications exportées.
    `curl`, `intl`, SQLite/PDO, `zip` et GD sont ajoutés dans le même lot.

36. **Compilateur natif — phase 1** : une chaîne de compilation s’ajoute au
    runtime interprété, qui reste la référence de compatibilité. La cible
    long terme est un sous-ensemble PHP strict compilé en C11 puis GTK4/Adw,
    avec des erreurs explicites hors périmètre. La première étape compile les
    formulaires du concepteur : `.form.json → FormDocument → CGenerator →
    gcc + GTK4 → ELF`. `FormCompiler` produit aussi le C seul (`--emit=c`)
    lorsque les en-têtes GTK4 manquent. Le binaire généré ne dépend ni de PHP
    ni de FFI. L’IDE expose l’action **Compiler en natif** dans le concepteur,
    avec repli documenté sur la génération C. Les phases suivantes ajoutent
    lexer/parser/AST/IR du sous-ensemble PHP, puis le runtime UI natif et les
    modules natifs. Voir `docs/compiler.md`.

37. **Compilateur PHP — phase 2** : le sous-ensemble PHP est compilé par un
    pipeline séparé `Lexer → Parser → AST → SemanticAnalyzer → IrBuilder →
    PhpCGenerator → gcc`. Le typage est statique et strict : types scalaires
    (`int`, `float`, `bool`, `string`), fonctions typées, `if`/`elseif`/`else`,
    `while`, `echo`, opérateurs usuels et quelques natives (`strlen`, `intval`,
    `floatval`, `strval`, `abs`). Les constructions hors périmètre (`for`,
    `foreach`, tableaux, objets, classes, closures, interpolation, exceptions)
    échouent avec un message et une ligne. Le backend produit un ELF autonome
    avec `runtime/native/cyonima_runtime.h` ; les chaînes concaténées sont
    allouées sans libération pour l’instant. La CLI expose `--php=FILE`. Voir
    `docs/compiler.md`.

38. **Tableaux, boucles et interpolation** : le sous-ensemble PHP ajoute
    `for`, `foreach`, les tableaux indexés homogènes (`[...]`, `array(...)`,
    `$a[i]`, `$a[]`, `count`) et l’interpolation `"Bonjour $nom"` /
    `"{$nom}"`. Les tableaux sont typés par élément (`array<int>`, etc.) et
    copiés à l’affectation ; les tableaux associatifs, imbriqués et les
    signatures de fonctions avec tableaux restent hors périmètre. L’analyse
    de flux vérifie l’affectation définie sur tous les chemins et les retours
    exhaustifs des fonctions non `void`.

39. **Runtime mémoire structuré** : toutes les allocations passent par
    `cy_alloc`/`cy_free`. Chaque fonction pousse une frame de racines pour ses
    variables et temporaires chaîne/tableau. Une GC mark-sweep est déclenchée
    aux retours de boucle selon des seuils d’allocations et d’octets ;
    `cy_runtime_shutdown` libère le reste à la sortie via `atexit`. Le modèle
    reste simple (pas de refcount ni de compaction) mais borne la mémoire des
    boucles longues.

40. **Runtime UI natif procédural** : une première couche `ui_*`
    (`ui_init`, `ui_window`, `ui_box`, `ui_label`, `ui_button`, `ui_entry`,
    `ui_append`, `ui_set_child`, `ui_present`, `ui_on_click`,
    `ui_on_activate`, `ui_after`, `ui_set_text`, `ui_entry_text`, `ui_quit`,
    `ui_run`) relie le PHP compilé à GTK4 sans FFI. Les widgets sont des
    handles `int` (1..256). Les gestionnaires sont des fonctions compilées
    `function h(): void` ou `function h(int $handle): void`, transmis comme
    chaînes littérales et reliés aux signaux GTK par des wrappers C. La façade
    objet `Cyonima\UI` sera construite au-dessus. La compilation ELF d’une
    application UI exige GTK4 de développement.

41. **Tables associatives** : le sous-ensemble ajoute `['clé' => valeur]`,
    l’accès et l’affectation par clé, `foreach` clé/valeur et `count`. Les
    tables sont typées `array<string, T>` avec des valeurs homogènes ; les
    clés sont des chaînes, les clés entières associatives restent hors
    périmètre. Le runtime ajoute `CyMap` (vecteur d’entrées, ordre
    d’insertion, recherche linéaire dans cette version), la copie à
    l’affectation, les racines `CY_ROOT_MAP` et le marquage GC des clés et
    valeurs. Une clé absente ou un `[]` sur une table associative échoue
    explicitement.

42. **switch, match et isset** : `switch` est abaissé en comparaisons et
    sauts, avec fall-through, `default` à n’importe quelle position et
    `break` limité au `switch`. `match` est une expression à comparaison
    stricte, `default` obligatoire et branches de types compatibles. `isset`
    accepte un seul accès `$tableau[$clé]` sur un tableau indexé ou une table
    associative, sans lever d’erreur si la clé manque. L’analyse de flux
    reconnaît les retours exhaustifs via `switch` lorsque `default` est
    présent et que tous les chemins retournent.

43. **Exceptions** : `throw` transporte une chaîne, `try`/`catch (string $e)`
    capture une exception, une seule clause `catch` et pas de `finally`. La
    propagation utilise un drapeau global contrôlé après chaque appel, sans
    `setjmp`/`longjmp` : les fonctions reviennent normalement, les frames de
    racines et les cleanups C s’exécutent, puis l’appelant saute vers son
    `catch` ou son chemin d’exception. Une fonction non `void` retourne une
    valeur par défaut sur son chemin d’exception ; `main` affiche l’exception
    non capturée et retourne `1`. Le message est une racine GC globale entre
    `throw` et `catch`. Les erreurs fatales du runtime (bornes, clé absente)
    restent fatales dans cette version.

44. **Classes et objets** : classes top-level avec propriétés typées
    (`int`, `float`, `bool`, `string`), méthodes, `__construct`, `new`,
    `$this`, accès/affectation de propriétés et appels de méthodes. Les
    propriétés sont initialisées à zéro/`false`/`""` ; les objets sont alloués
    via `cy_object_new` avec une fonction de marquage GC par classe pour les
    propriétés chaîne. L’affectation d’objet partage la référence. Les
    méthodes sont compilées en fonctions C `fn_Classe__méthode` avec `$this`
    comme premier paramètre. La visibilité est acceptée mais ignorée. Pas
    encore d’héritage, interfaces, `static`, objets en paramètres/retours ou
    propriétés, valeurs par défaut de propriétés, `clone` ou `instanceof`.

45. **Closures et callbacks** : `function (...) use (...)` et
    `fn (...) => ...` produisent un type `callable(params):retour`. Les
    captures sont par valeur, copiées pour les tableaux/tables ; les arrow
    functions capturent automatiquement leurs variables libres. Le backend
    génère une structure `CyClosureData_N` (champ `invoke` + captures), une
    fonction `cy_closure_N` et une fonction de marquage GC des captures. Les
    appels `$fn(...)` utilisent un cast de pointeur de fonction adapté à la
    signature. Une closure anonyme doit déclarer son type de retour ; une
    arrow function peut l’inférer. Pas encore de capture par référence, de
    `$this` capturé, de closure `static`, ni de callables en paramètres,
    retours, propriétés ou tableaux.

46. **Façade objet UI** : un prélude `UiPrelude` est analysé uniquement
    lorsqu’un programme fait `new Application`, `Window`, `Box`, `Label`,
    `Button` ou `Entry`. Les classes encapsulent un handle `int` et délèguent
    aux fonctions `ui_*`. Les callbacks sont des closures `callable():void`
    enregistrées via `ui_on_click_closure`, `ui_on_activate_closure` et
    `ui_after_closure` ; les closures sont retenues par des racines GC
    supplémentaires. Le runtime utilise `gtk_init`, `GtkWindow` et une
    `GMainLoop` plutôt qu’une `GtkApplication`, ce qui évite la dépendance
    D-Bus/portail et rend le lancement déterministe. La composition passe
    encore par `$widget->handle` ; l’héritage et les objets en paramètres
    viendront ensuite.

47. **Héritage et objets en signatures** : `class X extends Y` hérite des
    propriétés et méthodes, avec surcharge de signature identique. Chaque
    classe possède une vtable ; les appels de méthode passent par un slot et
    dispatchent l’implémentation la plus dérivée. Les objets sont acceptés en
    paramètres, retours et propriétés ; un enfant est assignable à un parent,
    avec cast C explicite. Les constructeurs sont hérités si l’enfant n’en
    déclare pas. Les structures C gardent un préfixe compatible
    (`cy_vtable` + propriétés du parent), ce qui permet le dispatch et l’accès
    aux propriétés héritées. La façade UI utilise désormais `Widget` comme
    base et compose directement `$box->append($label)` /
    `$window->setChild($box)`. Pas encore d’interfaces, classes abstraites,
    `parent::`, covariance ni membres `static`.

48. **Interfaces, classes abstraites et parent::** : `interface` +
    `implements` (plusieurs interfaces), méthodes d’interface abstraites,
    `abstract class` non instanciable, `abstract function` sans corps,
    implémentation obligatoire dans les classes concrètes. Chaque objet porte
    un `CyObjectHeader` avec la vtable de classe et un tableau `cy_ifaces` ;
    les appels via une interface utilisent la vtable d’interface. `parent::`
    appelle directement l’implémentation parente (constructeur ou méthode),
    avec cast de `$this` vers la classe propriétaire. Les membres `static`,
    l’héritage d’interfaces et la covariance restent hors périmètre.

49. **Membres statiques** : `static` sur propriétés et méthodes. Une propriété
    statique est une globale C `cy_static_<Classe>_<propriété>`, déclarée une
    seule fois dans la classe propriétaire et partagée par les sous-classes.
    Les méthodes statiques sont compilées en fonctions C sans paramètre
    `$this` et ne figurent pas dans les vtables. `Classe::méthode()`,
    `Classe::$propriété`, `self::` et `self::$propriété` sont abaissés en
    appels/accès directs ; la classe cible est résolue à l’analyse. Toutes les
    propriétés statiques de type référence (chaîne, tableau/table, objet,
    closure) sont enregistrées comme racines GC permanentes via
    `cy_add_root_slot`. Sont refusés explicitement : `static::`, `parent::`
    statique, méthodes statiques abstraites ou d’interface, appels croisés
    instance/statique et valeurs par défaut de propriétés statiques.

50. **Héritage d’interfaces** : `interface B extends A` et
    `interface C extends A, B`. La résolution est récursive, détecte les
    cycles et fusionne les méthodes héritées dans l’ordre des parents puis des
    déclarations locales. Une redéclaration doit avoir une signature
    strictement identique ; les collisions entre parents sont refusées. La
    liste `interfaces` d’une interface contient tous ses ancêtres, et celle
    d’une classe est étendue transitivement : un objet implémentant une
    interface enfant est donc dispatchable via chaque interface parente, avec
    une vtable d’interface dédiée par interface. Une interface ne peut hériter
    que d’interfaces, une classe ne peut implémenter qu’une interface.

51. **Covariance des retours** : une méthode enfant peut déclarer un retour
    plus précis que celui du parent (`Dog` pour `Animal`, classe pour
    interface, interface fille pour interface parente). Les paramètres restent
    invariants ; les scalaires, `void` et les callables restent invariants.
    La vérification s’appuie sur une carte `rawTypes` construite avant la
    résolution, ce qui permet de comparer des types encore en cours de
    résolution sans dépendre des `ClassInfo` complets. Pour la fusion de
    plusieurs interfaces parentes, le retour le plus précis est conservé quel
    que soit l’ordre de déclaration ; deux retours non liés sont refusés.
    Aucun changement d’ABI : les vtables stockent des pointeurs de fonction
    opaques et les appels utilisent le type statique de la classe ou de
    l’interface.

52. **Tableaux en signatures** : annotations `array<T>` et
    `array<string,T>` dans les paramètres, retours, propriétés, méthodes,
    constructeurs et interfaces. Le code source reste du PHP valide : le type
    PHP est `array`, raffiné par les annotations PHPDoc `@param`, `@return`
    et `@var` (`array<int>`, `array<string,T>`). Le lexer attache le docblock
    au prochain jeton, le parser l’applique à la déclaration et l’analyse
    refuse `array` seul. Les tableaux restent homogènes et scalaires. La
    sémantique de valeur PHP est préservée : copie à l’affectation, au passage
    d’argument, au retour via l’affectation du résultat, et à l’écriture d’une
    propriété ou d’une propriété statique. Un littéral `[]` est raffiné par le
    contexte typé (argument, retour, affectation). Les tableaux de tableaux,
    les tableaux dans les closures et les callables restent hors périmètre.

53. **Capture par référence** : `function (...) use (&$x)` capture une
    variable via une cellule GC allouée sur le tas. La variable est « boxée »
    dans le scope qui la possède : toutes les lectures/écritures passent par
    la cellule, et les closures stockent un pointeur vers celle-ci. La cellule
    est un objet GC dont la fonction de marquage protège le contenu (chaîne,
    tableau/table, objet ou closure) via `cy_cell_mark_string` /
    `cy_cell_mark_pointer`. Une cellule survit au retour de la fonction et
    persiste entre callbacks GTK. Les closures imbriquées réutilisent la
    cellule existante ou boxent une variable locale du scope intermédiaire.
    Les variables capturées par valeur conservent la sémantique de copie
    existante. Sont refusés : `use (&$this)`, les captures dupliquées, les
    variables non définies ou non affectées sur tous les chemins, et les
    paramètres par référence `&$x`.

54. **Modules natifs** : interface `Cyonima\Compiler\Native\NativeModule`
    déclarant signatures PHP, includes C, code des shims et options de liaison.
    Le registre active automatiquement un module lorsqu’une de ses fonctions
    est appelée, ou via `--module=NAME`. Les fonctions natives sont injectées
    dans l’analyse sémantique comme des `FunctionInfo` ; elles ne font pas
    partie de l’AST et le backend les appelle directement sous le nom
    `fn_<nom>`. Le module `sqlite` fournit des handles `int`, des requêtes
    scalaires et une copie des textes dans le tas managé ; son code est émis
    avant les fonctions utilisateur, et les options de `pkg-config` sont
    ajoutées à l’édition de liens. Un conflit entre fonction utilisateur et
    fonction native est refusé. `libcurl` et `libssh2` restent à ajouter.

55. **Modules libcurl et libssh2** : `curl_*` expose un handle (URL, méthode,
  corps, en-têtes), `curl_perform`, le statut HTTP, le corps et l’erreur ;
  les tampons internes utilisent malloc/realloc et les résultats texte sont
  copiés avec `cy_strdup`. `ssh2_*` expose la connexion TCP + handshake,
  l’authentification mot de passe, l’exécution de commande, le statut de
  sortie, l’erreur et la déconnexion ; l’erreur de connexion sans handle est
  conservée dans un état global. Les deux modules sont liés via pkg-config
  (`libcurl`, `libssh2`) et activés automatiquement ou par `--module`.
  `_POSIX_C_SOURCE=200809L` est défini en tête du C généré pour exposer
  `getaddrinfo`. Les tests curl utilisent une URL `file://` ; les tests ssh2
  vérifient le chemin d’échec de connexion, le chemin complet ayant été
  validé avec un serveur Paramiko local.

56. **Packaging direct natif** : `--package=appimage|flatpak` compile puis
  assemble l’application dans la foulée. `NativePackager` lit
  `.cyonima/project.json` (nom, appId, version), force la sortie ELF et appelle
  un script dédié. L’AppImage installe le binaire dans `/usr/bin`, collecte
  récursivement ses dépendances non système, ajoute GTK4/Adw, les schémas
  GSettings, les loaders GdkPixbuf et les icônes si le binaire dépend de GTK,
  puis génère l’AppImage. Le Flatpak utilise `org.gnome.Platform//49`, installe
  le binaire, un lanceur avec `LD_LIBRARY_PATH` et les bibliothèques déclarées
  par les modules natifs (`libssh2.so.1` pour ssh2), puis produit un bundle
  installable. Aucun interpréteur PHP n’est embarqué dans ces paquets natifs.
  Les deux formats ont été validés avec un programme console, SQLite et une
  application GTK4.

57. **Collections d’objets** : `array<Classe>` et
  `array<string,Classe>` sont supportés dans les littéraux, indexations,
  ajouts, `foreach`, paramètres, retours, propriétés instance et statiques,
  closures et signatures PHPDoc. Le runtime ajoute `CY_VALUE_OBJECT` et les
  opérations `cy_array_*_object` / `cy_map_*_object` ; le GC marque les objets
  contenus dans les tableaux et tables. Les éléments d’un littéral sont
  fusionnés vers un ancêtre commun ou une interface commune, et
  `array<Enfant>` est assignable à `array<Parent>` ou `array<Interface>`.
  Les éléments `callable`, les tableaux imbriqués et les annotations de
  closures restent hors périmètre. C’est le premier incrément de la feuille de
  route « Web-to-Desktop », avant les types nullables, les namespaces et le
  multi-fichiers.

58. **Types nullables** : `?string`, `?Classe`, `?Interface` et
  `?callable(signature)` en paramètres, retours, propriétés instance/statiques
  et captures. Les valeurs nullables sont représentées par le pointeur C
  (`NULL`), sans wrapper optionnel. `null` est assignable aux types nullables
  et comparable avec `===`/`!==`/`==`/`!=` ; les chaînes nullables sont
  concaténées/affichées comme une chaîne vide. L’accès à une propriété, l’appel
  de méthode et l’invocation d’une closure nullable émettent une garde
  runtime qui lève une exception `string` si la valeur est `NULL`, suivie
  d’un contrôle de propagation. Les retours covariants acceptent
  `T → ?Parent` et `?T → ?Parent`. Les scalaires optionnels (`?int`, etc.),
  les tableaux nullables, les éléments nullables et les signatures de
  closures/callables nullables restent hors périmètre.

59. **Namespaces et compilation multi-fichiers** : `namespace`, `use` et
  alias sont supportés, avec résolution relative au namespace/imports et noms
  absolus. Les fonctions non qualifiées tentent le namespace courant puis le
  global, ce qui préserve les builtins, les modules natifs et les fonctions
  globales. Les déclarations reçoivent un nom pleinement qualifié ; le backend
  C encode `\` en `__` (`App\Models\User` → `CyObj_App__Models__User`). Un
  projet peut lister des fichiers/dossiers dans `.cyonima/compiler.json`
  (`sources`) ou via `--sources` ; l’entrée reste `--php`. Les fichiers
  non-entry ne peuvent contenir que des déclarations, le code exécutable
  non-entry est refusé. La découverte est statique à la compilation : pas
  d’autoload runtime, pas de Composer, pas de `require`/`include`. Les
  namespaces multiples par fichier, les namespaces avec accolades, `use
  function`/`use const` et `use` groupé restent hors périmètre.

60. **JSON natif** : `json_encode` est un builtin dispatché à la compilation
  selon le type statique (scalaires, `?string`, listes/tables de scalaires,
  tableau vide, `null`), avec échappement complet des chaînes. Le décodage est
  typé : `json_decode_int/float/bool/string`, `json_decode_array_*` et
  `json_decode_map_*`, car le langage n’a pas de `mixed`. Le runtime contient
  un encodeur JSON (buffer malloc/realloc, copie finale dans le tas managé) et
  un parseur plat avec échappements `\uXXXX` et paires de substitution. Une
  erreur de syntaxe ou de type lève une exception `string` propagée par le
  mécanisme existant. Les objets, closures, tableaux d’objets, structures
  imbriquées et le décodage non typé restent hors périmètre.

61. **Hiérarchie d’exceptions et finally** : `Exception`, `RuntimeException`,
  `LogicException`, `InvalidArgumentException` et `DomainException` sont
  injectées comme classes globales. `throw` accepte une chaîne ou une instance
  d’`Exception` ; les exceptions objets portent `message` et `getMessage()`.
  Le runtime conserve l’objet et son identifiant de classe, et le GC les
  marque. Chaque objet porte `cy_class_id`; une table de parents générée
  permet `catch (Parent $e)` via `cy_class_is_a`. Les `catch` multiples sont
  évalués dans l’ordre, les catches string et objets ne se mélangent pas.
  `finally` est généré sur le chemin normal et sur le chemin exceptionnel ;
  une exception en attente est suspendue/reprise pendant le finally. Un
  `return` dans un `try`/`catch` exécute les `finally` en attente. Limites :
  pas de `Throwable`/catch d’interface, pas de catch union, `break` dans un
  try/catch avec finally refusé, duplication du code finally.

62. **Console native** : prélude `Cyonima\Console` injecté à la demande
  (`Application`, `Command`, `Input`, `Output`, `Argument`, `Option`).
  `Command::configure()` déclare nom, description, arguments et options ;
  `Application::run(cli_args())` gère `list`, `help`, `--help`, `--version`,
  les arguments positionnels, les options longues/courtes et les erreurs avec
  code de sortie. `cli_args()` expose `argv` via `cy_cli_args()`, et
  `stderr_write` via `cy_cli_error()`. Ajout de `str_starts_with`,
  `str_contains`, `substr`, `substr_len`. Les paramètres CLI sont séparés
  (`--name value`), sans `--name=value`, sous-commandes ni couleurs ANSI. Le
  modificateur `final` est accepté et ignoré.

63. **Base de données native** : prélude `Cyonima\Database` (`Connection`,
  `Repository`, `Migration`, `Migrator`) injecté à la demande, avec activation
  automatique du module natif `sqlite`. `Connection` expose SQL, requêtes
  scalaires, row map `array<string,string>`, quoting SQL, transactions et
  fermeture. `Repository` fournit `count`, `exists(id)`, `delete(id)`, `row`,
  `execute`, `lastId`, `changes`. `Migrator` crée `cyonima_migrations`, applique
  chaque version une fois, utilise une transaction par migration et fait un
  rollback en cas d’échec. Ajout de `sqlite_query_row_string` et
  `sqlite_quote` au module. Pas d’ORM, de relations, de requêtes préparées ni
  de migrations descendantes ; les helpers `exists`/`delete` supposent une clé
  entière `id`.

64. **Validation typée** : prélude `Cyonima\Validation` injecté à la demande
  (`Validator`, `Rule`, `ValidationException`, règles `Required`, `MinLength`,
  `MaxLength`, `Email`, `Integer`, `Numeric`, `MinValue`, `MaxValue`, `In`).
  Les règles implémentent une interface, sont stockées dans une liste
  `array<RuleEntry>` et validées sur `array<string,string>`. Une seule erreur
  par champ est conservée. `validateOrFail()` lève une `ValidationException`
  qui expose `getErrors()` et un résumé dans `getMessage()`. Ajout de `trim`,
  `is_numeric`, `is_integer`. Limitations : chaînes uniquement, pas de regex,
  pas de messages personnalisés par règle, pas d’objets ni de tableaux
  imbriqués.

65. **Templates `.ui` compilés** : un mini-langage déclaratif (balises
  `window`, `box`, `label`, `button`, `entry`, directives `@params`, `@if`,
  `@elseif`, `@else`, `@endif`, `@foreach`, `@endforeach`, interpolation
  `{{ }}`) est traduit en une classe PHP `Cyonima\Templates\<Nom>` avec
  `static build(...): Window`, puis compilée comme le reste du programme.
  Aucun moteur de template n’est embarqué. Découverte dans `templates/`,
  `.cyonima/templates`, la clé `templates` de `compiler.json` et l’option
  `--templates`. Le prélude UI est injecté dès qu’un template est présent.
  Les types de `@params` utilisent les noms absolus (`App\Todo`) et les
  expressions restent du PHP du sous-ensemble analysé statiquement.
  Limitations : une seule fenêtre racine, pas de fragments ni d’inclusion,
  pas de balises imbriquées dans `label`/`button`, pas de CSS ni d’identifiants,
  recompilation à chaque build.

66. **ORM léger** : couche data mapper explicite au-dessus de SQLite, sans
  réflexion. `Entity` porte `id` (`0` = non persisté), `table()`, `values()`,
  `hydrate()`, `save()` et `delete()`. `Table` construit les `INSERT`/`UPDATE`
  avec quoting, fournit `find`, `findWhere`, `delete`, `exists`, `count`,
  `ids`. `Repository::store()` expose la table ; `Connection::transaction()`
  encapsule `BEGIN`/`COMMIT`/`ROLLBACK`. Ajout de
  `sqlite_query_column_int` et `Connection::columnInt`. Les repositories
  typés restent écrits à la main (`findOrFail`, `all(): array<Todo>`) car le
  sous-ensemble n’a ni génériques, ni réflexion, ni `static` de retour.
  Les identifiants SQL sont validés. Limitations : clé entière `id`, pas de
  relations, lazy loading, identity map, cascade, query builder, timestamps,
  soft delete ni transactions imbriquées ; `all()` fait N+1 requêtes.

67. **Widgets enrichis et CSS** : ajout de `CheckButton` (label, `active`,
  `setActive`, `onToggled`), `ProgressBar` (`fraction`, `setFraction`),
  `Separator`, et de méthodes communes `Widget::addClass`, `setTooltip`,
  `setSensitive`. `Application::loadCss()` installe une feuille globale via
  `GtkCssProvider`. Nouvelles primitives natives `cy_ui_check_button`,
  `cy_ui_progress_*`, `cy_ui_separator`, `cy_ui_add_class`, `cy_ui_add_css`,
  `cy_ui_set_tooltip`, `cy_ui_set_sensitive`, `cy_ui_on_toggle_closure`.
  Les templates gagnent `<check-button>`, `<progress-bar>`, `<separator>` et
  les attributs communs `class`, `tooltip`, `sensitive` ; `class` accepte
  plusieurs noms ou une classe dynamique. Limitations : CSS global seulement,
  pas d’identifiants de widgets, callbacks toujours `callable():void`.

68. **Réduction des limites UI** : le registre de widgets devient dynamique
  (`realloc`), la limite de 256 disparaît. `Widget::setName()` et `setSize()`
  exposent les identifiants CSS et les tailles demandées. Ajout de `Image`,
  `ScrolledWindow`, `Entry::setText()`, `Entry::activate()` et des balises
  `<image>`, `<scrolled>`, `<style>`. Le bloc `<style>` est compilé en
  `Window::loadCss()` et reste global à l’affichage, mais ciblable par `#id`
  ou `.classe`. Les closures stockent leur arité (`CyClosure.arity`) :
  `onToggled` accepte `callable():void` ou `callable(bool):void`,
  `onActivate` accepte `callable():void` ou `callable(string):void`. Le
  dispatch se fait à l’exécution selon l’arité. Limites restantes : pas de
  fragments de template, de listes GTK, de menus ni de modèles de données.

69. **Fragments de templates** : un `.ui` sans `<window>` devient une classe
  `Cyonima\Templates\<Nom>` dont `build()` retourne une `Box`. Les fragments
  sont découverts automatiquement et peuvent être inclus à la demande par
  `<include file="..." />` ; le chemin est relatif au template parent et les
  paramètres sont passés par nom dans l’ordre de `@params`. Les types scalaires
  acceptent des littéraux, les objets/tableaux/callables exigent
  `{{ expression }}`. L’include d’une page (`<window>`) et l’auto-inclusion
  directe sont refusés. Les `<style>` de fragment sont compilés en
  `ui_add_css()`. Les cycles indirects ne sont pas détectés.

70. **Listes et modèles de chaînes** : `StringList` encapsule un
  `GtkStringList` dans un registre de modèles natif séparé (`ui_string_list*`,
  `ui_drop_down*`, `ui_list_box*`). `DropDown(StringList, selected)` expose
  `selectedIndex`, `selectedText`, `setSelected`, `onChanged` ; le signal
  `notify::selected` transmet un index (`int`) ou le texte (`string`) selon
  la signature de la closure. `ListBox` expose `append`, `selectedIndex`,
  `selectIndex`, `setSelectionMode`, `activate`, `onRowActivated` ; chaque
  enfant ajouté est automatiquement enveloppé dans un `GtkListBoxRow`.
  `CyClosure` stocke en plus `param_kind` pour distinguer `int`, `bool`,
  `string`, `float` et objet au moment du dispatch. Templates : `<drop-down>`
  avec `options` (liste statique ou `array<string>`) ou `model`, et
  `<list-box>` avec `selection` et `on-row-activated`. Limites : pas de
  `GtkListView`, pas de modèle métier typé ni de liaison automatique.

71. **Modèle métier typé** : interface `Listable` (`listLabel(): string`) et
  `ObjectList(array<Listable>)` (`add`, `count`, `item`, `labels`).
  `DropDown::fromObjects(ObjectList, selected)` dérive un `StringList` des
  libellés, conserve l’`ObjectList` et expose `selectedObject(): ?Listable`.
  `ListBox::appendObject` / `appendObjects` créent une ligne par objet.
  Templates : `<drop-down items="{{ $objects }}" />` et
  `<list-box items="{{ $objects }}" />`, mutuellement exclusifs avec `options`
  et `model`. Les collections `array<Todo>` sont acceptées comme
  `array<Listable>` par covariance. Limitations : pas de `GtkListView`, pas de
  liaison bidirectionnelle, `selectedObject()` dépend de l’index courant.

72. **Menus popover** : `Popover` (`setChild`) et `MenuButton`
  (`setLabel`, `setPopover`, `popup`, `popdown`) s’appuient sur
  `GtkPopover` / `GtkMenuButton`. Le template `<menu-button label>` enveloppe
  automatiquement son contenu dans un `Popover` et une `Box` paramétrable
  (`vertical`, `spacing`). Les entrées sont des widgets ordinaires, donc les
  callbacks, séparateurs, classes et images existants fonctionnent.
  `popup()` exige un menu attaché à une fenêtre. Limitation : pas de `GMenu`,
  `GtkPopoverMenuBar` ni de `GAction`.

73. **Barre de menus native** : `Menu` encapsule un `GMenu` et
  `MenuBar` un `GtkPopoverMenuBar`. Les entrées deviennent des
  `GSimpleAction` dans un `GSimpleActionGroup` global inséré dans la barre
  sous le préfixe `cymenu` ; les actions sont nommées `itemN` et les items les
  référencent par action détaillée. `Menu::addItem`, `addSeparator`,
  `addSubmenu`, `activate` ; `MenuBar::add(label, menu)`. Templates :
  `<menu-bar>`, `<menu label>`, `<item label on-click>`, `<separator>`.
  Limitations : callbacks sans argument, pas d’accélérateurs, d’items
  cochables/radio, d’icônes ni d’états dynamiques.

74. **Raccourcis clavier** : `Window::shortcut(accelerator, callable)` crée un
  `GtkShortcutController` avec un `GtkShortcut` (`gtk_shortcut_trigger_parse_string`
  + `gtk_callback_action_new`) attaché à la fenêtre. Les callbacks sont sans
  argument, conservés par les racines GC. Template : `<shortcut key
  on-activate>` collecté au niveau fenêtre ; interdit dans un fragment.
  Accélérateurs au format GTK (`<Control>q`, `F5`, ...). Limitations : portée
  fenêtre uniquement, pas d’accélérateur affiché dans les menus, pas de
  combinaisons dynamiques.

75. **Items de menu enrichis** : `Menu::addStyledItem(label, accel, icon,
  callback)` et `addCheckItem(label, checked, accel, icon, callback)`.
  `GMenuItem` reçoit l’attribut `accel`, une `GIcon` thématique, et
  l’attribut `toggle` pour les éléments cochables. Les items cochables
  utilisent une `GSimpleAction` stateful booléenne ; `change-state` met à jour
  l’état et appelle la closure (`bool` ou sans argument). `Menu::setChecked`
  change l’état par programme. Template : attributs `accel`, `icon`,
  `checkable`, `checked`, `on-toggle` ; `on-click` et `on-toggle` exclusifs.
  Limitations : pas d’items radio, pas d’activation/désactivation dynamique,
  icônes limitées aux noms de thème.

76. **Items radio et états dynamiques** : `Menu::addRadioItem(group, label,
  value, checked, accel, icon, callback)` crée une `GSimpleAction` stateful
  string par item, avec cible `value`, attribut `radio` et un registre de
  groupes natif. La sélection met l’état de l’item à sa valeur et remet les
  autres à `""` ; le callback reçoit la valeur (`string`) ou rien.
  `Menu::setRadio(position)` sélectionne par programme. `setEnabled` /
  `isEnabled` agissent sur `GSimpleAction::enabled`. Template : attributs
  `radio`, `value`, `on-select`, `enabled`. Limitations : pas de sous-groupes
  radio imbriqués ni de valeurs non scalaires.

77. **Binding bidirectionnel** : `Binding` relie un widget à un état via des
  callables typés. Fabriques `entry(getter():string, setter(string))`,
  `label`, `check(getter():bool, setter(bool))`, `dropIndex(int)`,
  `dropText(string)`, `listIndex(int)`. Le widget met à jour l’état via les
  signaux GTK (`changed`, `toggled`, `selected`, `row-activated`) ; l’état met
  à jour le widget via `refresh()`, appelé automatiquement à la création.
  Ajout de `Entry::onChanged`, `Label::text` et `DropDown::selectText`.
  Limitation : pas de notifications de propriétés ; le sens modèle → widget
  reste explicite.

78. **GtkListView** : `ListView(StringList, selected)` crée un `GtkListView`
  adossé à `GtkSingleSelection` et à une `GtkSignalListItemFactory` dont les
  lignes sont des `GtkLabel` (classe `list-row`). Sélection par index ou texte,
  activation par position, `selectedObject()` via `ListView::fromObjects`,
  `setSingleClick`. Template : `<list-view>` avec `options`, `model` ou `items`,
  `on-activate`, `single-click`. Bindings `viewIndex` / `viewText`. Le modèle
  `GtkStringList` peut évoluer après création. Limitations : lignes limitées
  à des libellés, pas de factory personnalisée ni de sélection multiple.

79. **Diagnostics de templates** : `TemplateCompiler` suit la ligne source et
  préfixe les erreurs par `template ligne N`. Les nœuds AST portent leur ligne
  et les lignes générées sont annotées par `// template-line N`. `PhpCompiler`
  décale chaque template dans un espace de lignes distinct (100000+) et
  remappe les erreurs du parseur et de l’analyse sémantique vers le fichier
  `.ui` et sa ligne. Les erreurs incluent le chemin relatif au projet.

80. **Callables typés avec objets** : les signatures `callable(Todo):string`,
  `callable(string):Todo`, `callable(?Todo):void` sont acceptées pour les
  closures, propriétés, paramètres et retours. Le parseur autorise les classes,
  interfaces et nullables d’objet/`?string` ; les tableaux et scalaires
  nullables restent refusés. La validation sémantique vérifie l’existence des
  classes et la compatibilité des arguments. Le backend C utilise les pointeurs
  d’objets existants (`CyObj_*`) pour les paramètres et retours de closures.
  La compatibilité des signatures reste exacte (pas de contravariance).

81. **Notifications de modèle** : `StringList::onChanged(callable():void)` et
  `onIndexChanged(callable(int):void)` relient le signal `items-changed` de
  `GtkStringList`. Les bindings `dropIndex`, `dropText`, `viewIndex` et
  `viewText` s’abonnent au modèle et rejouent `refresh()` quand il change.
  Les bindings d’état applicatif restent explicites : pas de notifications de
  propriétés arbitraires.

82. **Relations ORM explicites** : `BelongsTo` (`row`, `exists`, `id`) et
  `HasMany` (`ids`, `count`, `exists`) sont accessibles via
  `Table::belongsTo` / `hasMany` et `Repository::belongsTo` / `hasMany`.
  Les noms de table et de clé étrangère passent par la validation
  d’identifiants existante. Les relations retournent des row maps ou des
  identifiants, l’hydratation typée reste écrite dans le repository.
  Limitations : pas de lazy loading, identity map, cascade, eager loading ni
  de requête jointe typée ; `HasMany` induit du N+1.

83. **Composition de widgets et démo complète** : balise `<widget
  value="{{ $widget }}" />` pour insérer un widget préconstruit dans un
  template. Le paramètre est typé `Widget` (ou sous-classe) et les bindings,
  callbacks et modèles restent gérés dans le code PHP. L’exemple
  `examples/native-demo` assemble template, SQLite, migrations, entités,
  `belongsTo`, validation, menu natif, raccourci, `ListView` et widgets
  préconstruits. Un mode `--auto` ferme l’application pour les tests.

84. **Requêtes préparées SQLite** : `Connection::prepare()` crée un
  `Statement` (`bindInt/Float/String/Bool/Null`, `step`, `execute`, `reset`,
  `finalize`, `columnInt/Float/String/Bool`, `row`, `error`). Les statements
  sont suivis dans un registre natif et utilisent `sqlite3_bind_*`,
  `sqlite3_step`, `sqlite3_column_*`. Les erreurs de préparation lèvent une
  `RuntimeException`. Le `Statement` doit être finalisé explicitement. Les
  helpers ORM continuent d’utiliser `quote()`.

85. **ORM sur requêtes préparées** : `Table::insert`, `update`, `find`,
  `findWhere`, `delete`, `exists`, `count`, `ids` et les méthodes `HasMany`
  utilisent `Connection::prepare`, `bind*`, `step` et `finalize` au lieu de
  concaténer les valeurs. Les valeurs restent des `array<string,string>` et
  sont liées en `string` ; les identifiants entiers sont liés en `int`.
  `Connection::quote()` reste disponible pour le SQL personnalisé.

86. **Eager loading simple** : `Table::findMany(array<int>)` et
  `whereIn(column, array<int>)` préparent un `SELECT ... WHERE ... IN (...)`
  et retournent un `Statement` à parcourir. `HasMany::statement()` fait de même
  pour une clé étrangère. Les résultats restent des row maps hydratées
  explicitement dans des maps `array<string,Entity>` indexées par id. Une seule
  requête remplace N appels à `find()`. Limitations : pas de graphe d’objets
  automatique, pas de jointures typées, liste d’ids non paginée.

87. **Query builder typé** : `Table::query()` / `Repository::query()`
  retournent un `QueryBuilder` qui construit un `SELECT` préparé avec
  `select(array<string>)`, `whereString/Int/Float/Bool`, `whereNull`,
  `whereNotNull`, `orderBy`, `limit`, `offset`, `get()`, `count()` et
  `exists()`. Les colonnes passent par la validation d’identifiants ; les
  opérateurs sont limités à `=`, `!=`, `<`, `<=`, `>`, `>=`, `LIKE` ; les
  valeurs sont liées via `Statement`. Limitations : pas de jointures,
  d’agrégats personnalisés ni de sous-requêtes.

88. **Jointures typées** : `QueryBuilder::join` / `leftJoin` ajoutent des
  `INNER JOIN` / `LEFT JOIN` avec table, colonne gauche et colonne droite.
  `selectAs(column, alias)` permet des row maps sans collision ;
  `Table::reference()` valide les références qualifiées (`users.name`). Les
  conditions et `orderBy` acceptent aussi ces références. Les valeurs restent
  liées par statements préparés. Limitations : pas d’alias de table, pas de
  jointures multiples sur la même table, pas d’agrégats ni de sous-requêtes.

89. **Recherche projet** : `ProjectSearch` parcourt récursivement les fichiers
  texte sous un projet, exclut `vendor`, `node_modules`, `dist`, `build`,
  `build-dir`, `.git`, limite la taille des fichiers et le nombre de résultats,
  et ignore les contenus binaires. `SearchPanel` ajoute une requête, un compte
  de résultats et une liste activable ; l’IDE ouvre le fichier et positionne
  le curseur sur la ligne. Recherche insensible à la casse, sans index.

90. **Remplacement projet** : `ProjectSearch::replace` réutilise le même
  parcours et les mêmes exclusions que la recherche. Les options
  `caseSensitive`, `wholeWord` et `regex` s’appliquent à la recherche comme au
  remplacement ; les références arrière (`$1`) sont supportées en mode regex.
  Le remplacement est appliqué fichier par fichier avec verrou d’écriture et
  renvoie le détail (`files`, `changes`, `replacements`). `SearchPanel` expose
  les trois bascules, un champ de remplacement, « Tout remplacer », et
  rafraîchit les résultats. L’IDE notifie le bilan et recharge le fichier
  courant s’il a été modifié, sauf s’il contient des changements non
  enregistrés. Limitations : pas d’aperçu diff, pas d’annulation globale, pas
  de remplacement sélectif par résultat.

91. **Onglets multiples** : l’IDE garde un document par fichier (contenu,
  langage, état modifié, ligne/colonne du curseur) dans l’ordre d’ouverture.
  Un seul `CodeEditor` et un seul buffer sont réutilisés : le contenu du
  document quitté est mémorisé, puis le document cible est rechargé avec
  l’historique d’annulation neutralisé. Les onglets sont reconstruits à chaque
  bascule, avec point de modification et fermeture. La fermeture d’un document
  modifié est refusée ; la fermeture de l’onglet actif active le voisin.
  Raccourcis : `Ctrl+W`, `Ctrl+Tab`, `Ctrl+Shift+Tab`, `Ctrl+Page_Up/Down`.
  `CodeEditor::moveCursorTo(ligne, colonne)` restaure la position exacte.
  Limitations : pas de réorganisation, pas de défilement horizontal de la
  barre d’onglets, pas d’historique d’annulation par document.

92. **Navigation rapide** : `QuickOpen` affiche un `GtkPopover` centré, avec un
  champ de saisie et une liste défilante. `QuickOpenMatcher` classe les chemins
  relatifs par pertinence : nom exact, préfixe de nom, sous-chaîne, puis
  sous-séquence floue avec bonus en début de segment. Les fichiers sont
  rechargés à chaque ouverture via `ProjectSearch::files`, sans index. Les
  flèches, Entrée, Échap et le clic sont gérés ; la sélection ouvre le fichier
  dans un onglet. Le popover est détaché de la fenêtre à sa fermeture pour
  éviter un avertissement GTK. Limitations : 50 résultats, pas de symboles ni
  de commandes, pas de score affiché.

93. **Palette de commandes** : le popover de sélection est extrait dans
  `QuickPick` (champ, liste, filtrage, clavier, détachement à la fermeture),
  réutilisé par `QuickOpen` et `CommandPalette`. `QuickOpenMatcher::matchLabels`
  applique le même score flou aux libellés de commandes. `CommandPalette`
  enregistre des actions nommées avec raccourci optionnel ; le choix ferme la
  palette puis exécute l'action. L'IDE expose les commandes Fichier, Affichage,
  Exécuter, Exporter et Aide. Limitations : pas d'historique des commandes
  récentes, pas de personnalisation des raccourcis, liste statique construite au
  démarrage.

94. **Problèmes LSP** : `Diagnostics` agrège les notifications
  `textDocument/publishDiagnostics` par fichier, normalise les URI `file://`,
  ignore les messages vides, borne les sévérités à 1–4 et trie par chemin,
  ligne, colonne. `ProblemsPanel` rend les entrées activables et affiche un
  résumé erreurs/avertissements. Le panneau bas devient interactif :
  `PROBLÈMES` (liste), `SORTIE` (espace réservé) et `TERMINAL` (VTE). L'onglet
  Problèmes affiche le nombre de diagnostics ; un clic ouvre le fichier à la
  ligne. Limitations : pas de filtre par sévérité ni par fichier, pas de
  regroupement arborescent, `SORTIE` non alimenté.

95. **Panneau Sortie** : `OutputLog` conserve des lignes horodatées avec canal
  optionnel (borne 2 000 entrées). `OutputPanel` les affiche dans un
  `GtkScrolledWindow` défilant automatiquement via `GtkScroll` (helper FFI sur
  `GtkAdjustment`, absent des bindings générés). L'exécution est journalisée au
  moment du lancement dans le terminal ; la compilation native journalise le
  résultat ELF ou le C généré ; l'export asynchrone journalise stdout, stderr,
  code de sortie et durée. Le panneau s'ouvre automatiquement au début d'une
  compilation ou d'un export ; `Vider` et la palette remettent le journal à
  zéro. Limitations : la sortie du terminal VTE n'est pas recopiée, pas de
  canaux filtrables ni d'export du journal.

96. **Intégration Git** : `GitRepository` découvre la racine via
  `rev-parse --show-toplevel`, lit la branche, le statut porcelain v1 `-z`, les
  diffs indexé/non indexé et expose `stage`, `stageAll`, `unstage` et `commit`.
  Les commandes passent par `proc_open` avec un tableau d'arguments,
  `GIT_TERMINAL_PROMPT=0` et `-C <racine>` : pas de shell ni d'invite
  interactive. `GitPanel` affiche branche, compteur, fichiers et états, permet
  l'indexation globale et le commit des fichiers indexés ; un clic ouvre le
  fichier. La branche alimente la barre de statut et le panneau se rafraîchit
  après sauvegarde. La palette expose actualiser, indexer tout et diff (dans
  Sortie). Limitations : pas de branches/merge/push/pull, pas de staging
  fichier par fichier, pas de résolution de conflits.

97. **Staging fichier par fichier** : chaque ligne du panneau Git porte deux
  boutons icône, ouvrir et indexer/désindexer. Le bouton bascule selon l'état :
  un fichier entièrement indexé (`index` actif, `worktree` vide) est désindexé
  avec `git reset -- <path>` ; sinon il est indexé avec `git add -- <path>`.
  `GitPanel::stageRow()` applique cette règle, `stage()`/`unstage()` exposent
  les opérations unitaires et rafraîchissent la liste. Les lignes ne sont plus
  activables au clic simple pour éviter qu'un clic sur un bouton n'ouvre le
  fichier ; l'ouverture passe par le bouton dédié ou `activateRow()` côté API.
  L'indexation globale `Indexer tout` reste disponible.

98. **Diff par fichier** : `GitRepository::fileDiff()` combine le diff indexé
  et le diff non indexé d'un chemin ; pour un fichier non suivi, il utilise
  `git diff --no-index -- /dev/null <path>` afin d'afficher le contenu complet
  en ajout. `GitPanel` ajoute un bouton **Diff** par ligne, délègue via un
  callback et expose `diffAt()`/`showDiff()`. L'IDE affiche le résultat dans
  `DiffPanel`, un `CodeEditor` en lecture seule avec le langage GtkSourceView
  `diff`, dans un nouvel onglet **DIFF** du panneau bas. La commande de palette
  « afficher le diff du projet » cible désormais cet onglet. Limitations : pas
  de navigation entre hunks, pas d'édition du patch, pas de diff binaire.

99. **Gestion des branches locales** : `GitRepository::branches()` liste
  `refs/heads` via `for-each-ref` ; `createBranch()` lance
  `git checkout -b <nom>` et `switchBranch()` vérifie l'existence puis lance
  `git checkout <nom>`. Les noms vides ou inconnus sont refusés. `GitPanel`
  expose un bouton de branche ouvrant un popover (branche courante marquée
  `●`), un champ « Nouvelle branche » et un bouton **Créer**. Une bascule
  réussie rafraîchit le panneau, met à jour la barre de statut et notifie
  l'IDE via `onBranchChanged`, qui recharge le fichier courant s'il est propre.
  Le popover est détaché dans le destructeur du panneau. Limitations : pas de
  merge, rebase, push/pull, suppression, renommage ni branches distantes.

100. **Merge de branches** : `GitRepository::merge()` vérifie que la branche
  existe puis lance `git merge --no-edit <branche>`. `GitPanel` ajoute un champ
  « Fusionner une branche » et un bouton **Fusionner** ; le résultat est
  notifié, le panneau est rafraîchi dans tous les cas (les conflits apparaissent
  en `UU`) et le fichier courant est rechargé si la fusion réussit et qu'il est
  propre. La palette et le menu Affichage exposent « fusionner une branche ».
  Limitations : pas de rebase, de résolution assistée des conflits, ni de
  merge de branches distantes.

101. **Assistant de conflits** : `GitRepository::status()` ajoute un drapeau
  `conflict` calculé sur les codes porcelain (`U`, `AA`, `DD`).
  `conflicts()` filtre ces entrées, `mergeInProgress()` teste la présence de
  `MERGE_HEAD` (avec `clearstatcache`, le cache de stat de PHP masquant sa
  suppression), `resolve()` indexe `git add -A -- <fichier>` et
  `abortMerge()` lance `git merge --abort`. `GitPanel` affiche les conflits en
  rouge, remplace l'action de ligne par **Marquer résolu**, ajoute le nombre de
  conflits au résumé et affiche un bouton **Abandonner** tant que le merge
  dure. Après résolution du dernier conflit, l'utilisateur est invité à
  committer. Limitations : pas d'éditeur de conflits à trois volets ni de
  résolution automatique.

102. **Recherche de symboles** : `SymbolIndex::scan()` parcourt les fichiers
  `.php` retournés par `ProjectSearch::files` et utilise `token_get_all` pour
  relever classes, interfaces, traits, énumérations, fonctions et méthodes.
  L'espace de noms est reconstruit et les méthodes sont qualifiées
  `Namespace\Classe::méthode` ; la profondeur des accolades distingue méthodes
  et fonctions. `SymbolSearch` réutilise `QuickPick` et le filtre flou
  `matchLabels`, affiche le type et `chemin:ligne`, et ouvre le fichier via
  `openFile`. Raccourci `Ctrl+T`, menu Affichage et palette. Limites : 5 000
  symboles, pas de constantes/propriétés, définitions conditionnelles et
  classes anonymes ignorées, espace de noms à accolades non suivi.

103. **Indexation incrémentale des symboles** : `SymbolIndex` devient une
  instance avec cache mémoire `chemin => {mtime, size, symboles}`. À chaque
  `refresh()`, `clearstatcache` est appelé, les fichiers `.php` sont listés via
  `ProjectSearch::files`, les entrées disparues sont retirées et seuls les
  fichiers dont la taille ou la mtime change sont relus/tokenisés. `stats()`
  expose le nombre de fichiers en cache et le nombre analysé lors du dernier
  passage. `SymbolSearch` réutilise l'instance ; `invalidate($path)` est appelé
  après sauvegarde et après remplacement projet. Le cache est propre au
  processus, sans persistance disque.

104. **Surveillance des fichiers** : `FileWatcher` encapsule un `GFileMonitor`
  Gio via FFI (`libgio-2.0.so.0`), avec callback PHP conservé dans l'instance,
  annulation et libération explicites. `WorkspaceWatcher` crée un moniteur par
  dossier (hors répertoires exclus, plafond 200), regroupe les événements
  pendant 200 ms et fournit la liste des chemins touchés. L'IDE rafraîchit
  l'explorateur, invalide les fichiers dans `SymbolIndex` et rafraîchit Git ;
  les événements de structure reconstruisent les moniteurs. Tout est annulé à
  la fermeture de la fenêtre. Si Gio est indisponible, la surveillance est
  ignorée. Limitations : pas de surveillance des fichiers hors projet, plafond
  de dossiers, pas de persistance.

105. **Rechercher/Remplacer dans l’éditeur** : `TextSearch` fournit les
  correspondances et le remplacement avec options casse, mot entier et regex.
  `FindBar` (Ctrl+F/Ctrl+H) affiche les champs, les options, le compteur
  `position/total`, les boutons suivant/précédent, remplacer et tout
  remplacer. Le parcours boucle ; les remplacements unitaires utilisent
  `CodeEditor::selectRange`/`replaceRange` (nouveaux helpers `GtkText` sur
  offsets), le remplacement global part de la fin pour préserver les offsets.
  `Échap` ferme et rend le focus à l’éditeur ; la barre se rafraîchit quand le
  texte change. Limitations : pas de surlignage de toutes les occurrences, pas
  d’historique de recherches, remplacement unitaire littéral (les références
  arrière ne sont appliquées qu’au remplacement global).

106. **Surlignage des occurrences** : `GtkText` expose les tags de buffer
  (`create_tag` via varargs FFI, `apply_tag_by_name`, `remove_tag_by_name`) et
  `hasTagAtOffset` pour les tests. `FindBar` installe `cyonima-find`
  (fond brun) et `cyonima-find-current` (fond orange, texte blanc), applique le
  tag courant et les autres occurrences après chaque rafraîchissement, et
  nettoie tout à la fermeture. Le surlignage est plafonné à 500 occurrences
  pour rester fluide ; les correspondances au-delà restent navigables.
  Limitations : couleurs fixes, pas de configuration utilisateur.

107. **Références arrière par occurrence** : `TextSearch::replaceMatch()`
  applique le remplacement à la correspondance exacte, en développant `$1`,
  `$2`… uniquement en mode regex. Hors regex, `TextSearch` et `ProjectSearch`
  échappent `$` et `\` avant `preg_replace`, donc un remplacement littéral
  contenant `$1` reste inchangé. `FindBar` utilise `replaceMatch` pour
  « Remplacer » et pour chaque occurrence de « Tout remplacer ». Limitations :
  pas de syntaxe `${1}` étendue ni de transformations conditionnelles.

108. **Aperçu Markdown** : `Markdown::toPango()` convertit un sous-ensemble
  (titres, gras, italique, code inline et blocs, listes, citations, liens,
  images en texte, règles) en balisage Pango. Le HTML brut est échappé avant
  les styles ; les fragments de code sont protégés par placeholders. Le rendu
  est natif via `MarkdownPreview` (GtkLabel markup, sélectionnable), sans
  WebKit. L’IDE ajoute un volet `Paned` à droite de l’éditeur, togglé par
  `Ctrl+Shift+V` ou le menu Affichage, rafraîchi à chaque modification et
  synchronisé aux changements d’onglet ; il se ferme pour un fichier non `.md`.
  Limitations : tableaux, cases à cocher et HTML non rendus, liens non
  cliquables.

109. **Défilement synchronisé de l’aperçu** : `GtkScroll` expose les
  `GtkAdjustment` d’un `GtkScrolledWindow` (valeur, bornes, page), la
  connexion/déconnexion de `value-changed` et une synchronisation par fraction
  de la plage défilable. `ScrollSync` relie éditeur et aperçu dans les deux
  sens avec un verrou anti-rétroaction et des callbacks conservés côté FFI.
  L’aperçu est resynchronisé à l’ouverture et au changement d’onglet ; les
  handlers sont détachés à la fermeture. Limitations : synchronisation
  proportionnelle, pas alignement ligne à ligne, pas de synchronisation
  horizontale.

110. **Alignement ligne à ligne de l’aperçu** : `Markdown::toPangoMapped()`
  retourne le balisage et `map[ligne source] = ligne affichée`, en émettant les
  lignes de code une par une pour conserver la correspondance. `MarkdownPreview`
  expose `previewLineForSourceLine()` et `sourceLineForPreviewLine()`.
  `ScrollSync` accepte un mapper `(bool $fromSource, float $fraction): float`
  et applique la fraction calculée ; l’IDE convertit la fraction de défilement
  en ligne source, la mappe vers la ligne d’aperçu (ou l’inverse), puis
  reconstruit la fraction cible. Limitations : les lignes longues sont
  enveloppées par Pango, donc l’alignement reste approximatif à l’intérieur
  d’un paragraphe ; pas de synchronisation horizontale.

111. **Historique de recherches** : `SearchHistory` persiste les requêtes dans
  `Config::dir()/search-history.json`, déduplique, borne à 20 entrées et
  ignore les requêtes vides. `FindBar` l’alimente lors des actions
  rechercher/remplacer, expose `historyPrevious()`/`historyNext()` sur
  `Alt+↑`/`Alt+↓`, et un bouton d’historique revient à la requête précédente.
  La palette permet de vider l’historique. Les tests injectent un fichier
  temporaire pour ne pas toucher au profil utilisateur. Limitations : pas de
  liste déroulante, pas d’horodatage ni de synchronisation entre profils.

112. **Liste déroulante d’historique** : `FindBar` reçoit la fenêtre et crée un
  `QuickPick` dédié, rempli avec `SearchHistory::all()`. Le bouton historique
  ouvre cette liste filtrable ; la sélection remplit le champ sans relancer la
  recherche. Si aucune fenêtre n’est fournie (tests, usage isolé), le bouton
  retombe sur la requête précédente. Le popup est parenté à la fenêtre, évitant
  les warnings de mesure d’un popover ancré dans la barre. `Alt+↑/↓` reste
  disponible. Limitations : liste non groupée par date, maximum 20 entrées.

## Références de version (machine de développement)

| Composant | Version |
|---|---|
| PHP CLI | 8.3.6 |
| GTK | 4.14.5 |
| libadwaita | 1.5.0 |
| GtkSourceView | 5.12.0 |
| VTE (GTK4) | 0.76.0 |
| GObject Introspection | 1.80 (typelib lus via `libgirepository-1.0`) |
| PyGObject (référence de différentiation) | 3.48.2 |

Les sources de vérité des bindings sont les `.typelib` de
`/usr/lib/x86_64-linux-gnu/girepository-1.0/`, lisibles sans paquet `-dev`.
