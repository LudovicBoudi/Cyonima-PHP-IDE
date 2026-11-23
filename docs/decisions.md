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
21. **AppImage autonome (44 Mo)** : PHP 8.3.14 et toute la pile GTK
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
