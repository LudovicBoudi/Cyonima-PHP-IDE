# Compilateur natif

Cyonima IDE conserve son runtime PHP interprété, mais ajoute une chaîne de
compilation native. L’objectif n’est pas de compiler tout PHP 8 : le
compilateur couvre d’abord un **sous-ensemble strict**, et toute construction
hors périmètre doit échouer explicitement plutôt que produire un binaire
approximatif.

## Chaîne cible

```text
source PHP / formulaire .form.json
        │
        ▼
      AST
        │
        ▼
  analyse sémantique
        │
        ▼
       IR
        │
        ▼
   backend C11
        │
        ▼
 runtime natif + GTK4 / Adw
        │
        ▼
 ELF · AppImage · Flatpak
```

Le runtime PHP interprété reste la référence de compatibilité. Un projet qui
utilise une construction non compilable continue de fonctionner avec
l’interpréteur.

## Phase 1 — formulaires natifs

La première cible est le modèle produit par le concepteur d’interface :

```text
.cyonima/forms/<nom>.form.json
        │
        ▼
 FormDocument
        │
        ▼
    CGenerator
        │
        ▼
   C11 + GTK4
        │
        ▼
      ELF
```

Le code généré ne dépend ni de PHP, ni de la couche FFI, ni du runtime
Cyonima. Il appelle directement l’API C de GTK4.

### Contrôles pris en charge

| Type | Widget GTK |
|---|---|
| `label` | `GtkLabel` |
| `button` | `GtkButton`, éventuellement `GtkBox` + `GtkImage` + `GtkLabel` |
| `entry` | `GtkEntry` |
| `switch` | `GtkSwitch` + libellé |
| `image` | `GtkImage` |
| `video` | `GtkVideo` |
| `separator` | `GtkSeparator` |

Les contrôles sont placés dans un `GtkFixed` aux coordonnées du concepteur,
avec la taille demandée.

### Événements

Les événements déclarés dans le formulaire deviennent des fonctions C vides
avec la bonne signature GTK :

| Événement | Connexion générée |
|---|---|
| `click` sur un bouton | signal `clicked` |
| `changed` sur une entrée | signal `changed` |
| `activate` sur une entrée | signal `activate` |
| `changed` sur un interrupteur | signal `notify::active` |
| `click` / `double-click` générique | `GtkGestureClick` |

Les corps des handlers sont des stubs commentés. Ils constituent le point
d’entrée pour la phase 3, qui reliera le code généré aux actions du projet.

## Phase 2 — sous-ensemble PHP

La phase 2 ajoute le frontend PHP et un backend C autonome :

```text
fichier .php
    │
    ▼
 Lexer → Parser → AST
    │
    ▼
 SemanticAnalyzer (portée + types)
    │
    ▼
 IrBuilder (trois adresses)
    │
    ▼
 PhpCGenerator (C11)
    │
    ▼
   gcc → ELF
```

Le binaire produit est autonome : il ne dépend ni de PHP, ni de GTK.

### Constructions supportées

| Famille | Support |
|---|---|
| Balise | `<?php`, fichiers `.php` |
| Types | `int`, `float`, `bool`, `string`, `void` |
| Variables | `$nom`, affectation simple |
| Littéraux | entiers, flottants, chaînes, `true`, `false`, `null` |
| Chaînes | concaténation `.`, interpolation `"Bonjour $nom"` et `"{$nom}"` |
| Tableaux indexés | homogènes, `[...]`, `array(...)`, `$a[i]`, `$a[]`, `count` |
| Tableaux associatifs | `['clé' => valeur]`, `$a['clé']`, `foreach` clé/valeur, `count` |
| Opérateurs | `+ - * / % .`, comparaisons, `===`, `!==`, `&&`, `\|\|`, `!` |
| Contrôle | `if`, `elseif`, `else`, `while`, `for`, `foreach`, `switch` (fall-through, `break`), `match` |
| Vérification | `isset($a[$clé])` |
| Exceptions | `throw`, `try` / `catch (string $e)`, propagation entre fonctions |
| Classes | `class`, propriétés typées, `new`, `$this`, méthodes, `__construct` |
| Closures | `function (...) use (...)` et `fn (...) => ...`, appels `$fn(...)` |
| Fonctions | déclaration typée, paramètres, retour, appels |
| Sortie | `echo` multi-arguments |
| Fonctions natives | `strlen`, `intval`, `floatval`, `strval`, `abs`, `count` |
| Analyse | types, portée, affectation définie sur tous les chemins, retours exhaustifs |

Les types sont vérifiés : une variable ne change pas de type, un `int` peut
alimenter un `float`, les signatures et les arguments sont contrôlés. Une
variable utilisée après un `if` sans `else` doit avoir été affectée avant ou
dans les deux branches. Une fonction non `void` doit retourner sur tous les
chemins.

### Exemple compilé

```php
<?php
function factorial(int $n): int {
    $result = 1;
    while ($n > 1) {
        $result = $result * $n;
        $n = $n - 1;
    }
    return $result;
}

echo factorial(5), "\n";
```

```bash
bin/cyonima-compile \
  --project=./mon-projet \
  --php=src/factorial.php \
  --emit=elf

./mon-projet/dist/factorial
# 120
```

Un exemple complet et exécutable est fourni dans
[`examples/native-hello.php`](../examples/native-hello.php).

Les tableaux associatifs utilisent des clés `string` et des valeurs
homogènes :

```php
<?php
$ages = ['alice' => 30, 'bob' => 25];
$ages['carol'] = 40;

$total = 0;
foreach ($ages as $name => $age) {
    echo $name, ' : ', $age, "\n";
    $total = $total + $age;
}
```

Un exemple complet est fourni dans
[`examples/native-maps.php`](../examples/native-maps.php).

### switch, match et isset

`switch` est compilé en chaîne de comparaisons et sauts, avec fall-through et
`break` :

```php
switch ($value) {
    case 1:
    case 2:
        echo 'petit';
        break;
    default:
        echo 'autre';
}
```

`match` est une expression, avec comparaison stricte et `default` obligatoire :

```php
$label = match ($value) {
    1, 2 => 'petit',
    3 => 'trois',
    default => 'autre',
};
```

`isset` accepte exactement un accès indexé :

```php
if (isset($ages['alice'])) {
    echo $ages['alice'];
}
```

`switch` utilise la comparaison `==` du sous-ensemble (types identiques ou
numériques). `match` exige des conditions du même type que le sujet. Les
branches `match` doivent produire des types compatibles.

### Exceptions

```php
try {
    throw 'message';
} catch (string $error) {
    echo $error;
}
```

- `throw` attend une chaîne ;
- `catch` attend un type `string` et une variable ;
- un seul `catch`, pas de `finally` ;
- propagation automatique entre fonctions : chaque appel est suivi d’un
  contrôle du drapeau d’exception ;
- une exception non capturée écrit sur `stderr` et termine le programme avec
  le code `1` ;
- le message est enregistré comme racine GC entre le `throw` et le `catch`.

Un exemple complet est fourni dans
[`examples/native-exceptions.php`](../examples/native-exceptions.php).

### Classes et objets

```php
class Point {
    public int $x;
    public int $y;

    public function __construct(int $x, int $y)
    {
        $this->x = $x;
        $this->y = $y;
    }

    public function sum(): int {
        return $this->x + $this->y;
    }
}

$p = new Point(1, 2);
echo $p->sum();
$p->x = 10;
```

- propriétés typées `int`, `float`, `bool`, `string`, initialisées à
  `0` / `false` / `""` ;
- constructeur `__construct` optionnel, appelé par `new Class(...)` ;
- méthodes compilées en fonctions C avec un paramètre `$this` ;
- accès et affectation `$objet->propriété`, appels `$objet->méthode()` ;
- affectation d’objet = partage de référence, comme en PHP ;
- GC : une fonction de marquage par classe protège les propriétés chaîne.

Un exemple complet est fourni dans
[`examples/native-classes.php`](../examples/native-classes.php).

### Closures et callbacks

```php
$factor = 3;

$multiply = function (int $x) use ($factor): int {
    return $x * $factor;
};

$add = fn (int $x): int => $x + $factor;

echo $multiply(4), ' ', $add(4);
```

- `function (...) use (...)` capture explicitement des variables par valeur ;
- `fn (...) => ...` capture automatiquement les variables libres ;
- type interne `callable(int):int`, `callable():void`, etc. ;
- appels via `$fn(...)` ;
- captures scalaires, chaînes, tableaux, tables associatives, objets et
  closures ; les tableaux et tables sont copiés à la création ;
- l’état capturé persiste entre les appels d’une même closure ;
- codegen : structure `CyClosureData_N`, fonction `cy_closure_N`, champ
  `invoke` et fonction de marquage GC des captures.

Une closure anonyme doit déclarer son type de retour. Une arrow function peut
l’omettre : il est inféré depuis l’expression.

Un exemple complet est fourni dans
[`examples/native-closures.php`](../examples/native-closures.php).

### Limites explicites

Les constructions suivantes sont refusées avec un message et un numéro de
ligne :

- clés associatives entières (les tables associatives sont `string => valeur`) ;
- tableaux imbriqués, tableaux passés en paramètre ou retournés par une fonction ;
- `break`/`continue` dans les boucles (seul `break` de `switch` est supporté) ;
- `match` sans `default` et `isset` avec plusieurs arguments ;
- héritage, interfaces, classes abstraites, membres `static`, application de la
  visibilité (les mots-clés sont acceptés mais ignorés) ;
- objets en paramètres, retours ou propriétés, valeurs par défaut de
  propriétés, `clone`, `instanceof` ;
- capture par référence `use (&$x)`, capture de `$this`, closures `static` ;
- closures en paramètres, retours, propriétés ou éléments de tableau,
  invocation immédiate `(function () {})()` ;
- `eval`, réflexion, `include`/`require`, autoload ;
- références (`&$x`), `++`/`--`, opérateurs combinés (`+=`) ;
- hiérarchies d’exceptions, `finally`, multi-catch, exceptions typées autres
  que `string` ;
- exceptions provenant des erreurs fatales du runtime (index hors limites,
  clé absente) : elles terminent encore le programme ;
- générateurs, fibres ;
- index ou clé absents : le runtime s’arrête avec un message explicite.

Les tableaux indexés sont **homogènes** (`int`, `float`, `bool` ou `string`).
Les tables associatives utilisent des clés `string` et des valeurs homogènes.
Une affectation de tableau ou de table associative crée une copie, comme en
PHP pour les affectations par valeur.

Un tableau vide `[]` doit être raffiné par un premier ajout indexé (`$a[] =`)
ou une première clé (`$a['clé'] =`) avant tout autre usage : sa nature
(indexée ou associative) et son type de valeur doivent être connus à la
compilation.

### Modèle mémoire

Le runtime natif (`runtime/native/cyonima_runtime.h`) centralise toutes les
allocations :

- `cy_alloc` / `cy_free` remplacent `malloc` et `realloc` dans le code généré ;
- chaque fonction pousse une **frame de racines** contenant ses variables et
  temporaires de type chaîne, tableau ou table associative ;
- une **GC mark-sweep** est déclenchée aux retours de boucle lorsque le seuil
  d’allocations ou d’octets est atteint ;
- `cy_runtime_shutdown` libère le reste à la sortie du programme via `atexit`.

Les chaînes littérales restent statiques. Les chaînes et tableaux alloués sont
marqués depuis les frames de racines ; les objets non marqués sont libérés. La
GC est volontairement simple (pas de refcount, pas de compaction) mais elle
borne la mémoire des boucles longues et constitue la base du runtime natif.

## Phase 3 — runtime UI natif GTK4

Une première couche UI native relie le sous-ensemble PHP à GTK4 sans FFI.
Le compilateur génère les appels GTK et les wrappers de callbacks :

```text
PHP ui_*  →  IR  →  C11 + cyonima_ui.h  →  GTK4  →  ELF
```

Le runtime `runtime/native/cyonima_ui.h` maintient une table de widgets
référencés par des handles `int` (1..256). Il initialise GTK avec `gtk_init`,
crée des `GtkWindow`, les boîtes et les contrôles, puis lance une `GMainLoop`.
Il relie les signaux GTK aux fonctions ou closures PHP compilées.

### Fonctions UI

| Fonction | Rôle |
|---|---|
| `ui_init(string $appId)` | initialise GTK et la boucle |
| `ui_window(string $title, int $w, int $h): int` | fenêtre |
| `ui_box(bool $vertical, int $spacing): int` | boîte de disposition |
| `ui_label(string $text): int` | libellé |
| `ui_button(string $text): int` | bouton |
| `ui_entry(string $placeholder): int` | champ de saisie |
| `ui_append(int $box, int $child)` | ajoute un enfant à une boîte |
| `ui_set_child(int $window, int $child)` | contenu d’une fenêtre |
| `ui_present(int $window)` | affiche la fenêtre |
| `ui_set_text(int $label, string $text)` | met à jour un libellé |
| `ui_entry_text(int $entry): string` | lit la saisie |
| `ui_set_visible(int $widget, bool $visible)` | visibilité |
| `ui_on_click(int $button, string $handler, int $data = 0)` | clic (nom de fonction) |
| `ui_on_activate(int $entry, string $handler, int $data = 0)` | validation d’entrée |
| `ui_after(int $ms, string $handler, int $data = 0)` | timer unique |
| `ui_on_click_closure(int $button, callable():void $cb)` | clic (closure) |
| `ui_on_activate_closure(int $entry, callable():void $cb)` | validation (closure) |
| `ui_after_closure(int $ms, callable():void $cb)` | timer (closure) |
| `ui_click(int $button)` | émet un clic (tests/démo) |
| `ui_quit()` | arrête la boucle GTK |
| `ui_run(): int` | lance la boucle GTK |

### Gestionnaires

Un gestionnaire est une fonction PHP compilée, désignée par une **chaîne
littérale** :

```php
function show(int $label): void {
    ui_set_text($label, 'Cliqué !');
}

function quit(): void {
    ui_quit();
}
```

Deux signatures sont acceptées :

- `function handler(): void` ;
- `function handler(int $handle): void`, où `$handle` reçoit le troisième
  argument de `ui_on_click`, `ui_on_activate` ou `ui_after`.

Le compilateur génère un wrapper C qui convertit le `gpointer` GTK en handle
entier et appelle la fonction compilée.

### Exemple complet

```php
<?php
function show(int $label): void {
    ui_set_text($label, 'Cliqué !');
}

function quit(): void {
    ui_quit();
}

ui_init('com.cyonima.nativeui');
$window = ui_window('Cyonima natif', 400, 200);
$box = ui_box(true, 12);
$label = ui_label('Bonjour');
$button = ui_button('Cliquer');
$quitButton = ui_button('Quitter');
ui_append($box, $label);
ui_append($box, $button);
ui_append($box, $quitButton);
ui_set_child($window, $box);
ui_on_click($button, 'show', $label);
ui_on_click($quitButton, 'quit');
ui_present($window);
ui_run();
```

Un exemple prêt à compiler est fourni dans
[`examples/native-ui.php`](../examples/native-ui.php).

### Façade objet

La façade `Application` / `Window` / `Box` / `Label` / `Button` / `Entry` est
injectée automatiquement lorsqu’un programme utilise `new` sur l’une de ces
classes. Elle encapsule les handles et les appels `ui_*`.

```php
$app = new Application('com.cyonima.nativeui.object');
$window = new Window('Cyonima objet', 400, 200);
$box = new Box(true, 12);
$label = new Label('Bonjour');
$button = new Button('Cliquer');

$box->append($label->handle);
$box->append($button->handle);
$window->setChild($box->handle);

$button->onClicked(fn () => $label->setText('Cliqué !'));

$window->present();
$app->run();
```

- les callbacks sont des closures `callable():void` enregistrées via
  `ui_on_click_closure` et maintenues en vie par les racines GC ;
- fermer la fenêtre arrête la boucle ;
- la composition utilise encore `$widget->handle` dans cette version ;
- un exemple complet est fourni dans
  [`examples/native-ui-object.php`](../examples/native-ui-object.php).

### Limites de cette première couche

- façade objet disponible, mais composition via `$widget->handle` ; pas
  encore d’héritage ni d’objets en paramètres/retours ;
- callbacks UI limités à `callable():void` ;
- une seule boucle GTK par programme, 256 widgets maximum ;
- pas encore de CSS, de menus, de listes ni de modèles de données ;
- la compilation ELF d’une application UI exige `libgtk-4-dev`.

La façade objet `Cyonima\UI` (`new Button(...)`, `onClicked(...)`) sera
construite au-dessus de cette couche dans une étape ultérieure.

## Utilisation

```bash
# Formulaire : binaire ELF GTK4 (GTK4 de développement requis)
bin/cyonima-compile \
  --project=/chemin/du/projet \
  --form=main-form \
  --emit=elf

# Formulaire : code C seulement
bin/cyonima-compile \
  --project=/chemin/du/projet \
  --form=main-form \
  --emit=c

# PHP du sous-ensemble supporté : binaire autonome
bin/cyonima-compile \
  --project=/chemin/du/projet \
  --php=src/programme.php \
  --emit=elf

# Application GTK4 native : active automatiquement pkg-config gtk4
bin/cyonima-compile \
  --project=/chemin/du/projet \
  --php=examples/native-ui.php \
  --emit=elf
```

Options :

| Option | Rôle |
|---|---|
| `--project=DIR` | projet contenant `.cyonima/forms/` et `dist/` |
| `--form=NAME` | nom logique du formulaire, `main-form` par défaut |
| `--php=FILE` | fichier PHP du sous-ensemble compilable |
| `--emit=c\|elf` | code C seul, ou binaire natif |
| `--output=PATH` | sortie du binaire |
| `--help` | aide |

Sorties pour un formulaire :

```text
projet/
└── dist/
    ├── main-form          # binaire ELF (--emit=elf)
    └── native/
        └── main-form.c    # code C généré
```

Sorties pour un fichier PHP :

```text
projet/
└── dist/
    ├── programme          # binaire ELF
    └── native/
        └── programme.php.c
```

Le binaire d’un formulaire dépend de GTK4 à l’exécution. Un programme PHP sans
`ui_*` est autonome ; un programme avec `ui_*` dépend de GTK4 à l’exécution.
La génération C ne requiert que PHP.

## Prérequis

Le compilateur lui-même s’exécute avec PHP 8.3+ et l’extension `ctype`
(fournie par les runtimes AppImage et Flatpak de Cyonima).

Pour `--emit=elf` d’un formulaire ou d’un programme avec `ui_*` :

- `gcc` ou un compilateur C11 ;
- `pkg-config` ;
- les en-têtes GTK4 (`libgtk-4-dev` sur Debian/Ubuntu).

Pour `--emit=elf` d’un fichier PHP sans `ui_*` :

- `gcc` ou un compilateur C11 ;
- `libm` (fournie par la libc).

Sans ces prérequis, `--emit=c` reste disponible. L’IDE signale le manque de
GTK4 de développement et bascule sur la génération C pour les formulaires.

## Depuis l’IDE

Le concepteur d’interface expose le bouton **Compiler en natif**. Il sauvegarde
le formulaire courant, génère le C, puis tente l’ELF. En l’absence de chaîne
GTK4 de développement, le code C est tout de même produit et le message
indique le chemin du fichier.

Le menu **Exécuter → Compiler le fichier en natif** applique le compilateur PHP
au fichier ouvert. En l’absence de `gcc`, le C est généré et le message indique
le chemin.

## API PHP

```php
use Cyonima\Compiler\FormCompiler;
use Cyonima\Compiler\PhpCompiler;

$forms = new FormCompiler('/chemin/du/projet');
$form = $forms->compile('main-form', null, 'c');
echo $form['cPath'];

$php = new PhpCompiler('/chemin/du/projet');
$result = $php->compile('/chemin/du/projet/src/programme.php', null, 'elf');
echo $result['binaryPath'];
```

`compile()` lève `Cyonima\Compiler\CompilerException` lorsque la cible est
introuvable, lorsque la sortie demandée est inconnue, lorsqu’une construction
est hors périmètre, ou lorsque la compilation C échoue. Aucun binaire partiel
n’est présenté comme valide.

## Tests

La suite couvre :

- la génération C des formulaires (`#include <gtk/gtk.h>`) ;
- le placement des contrôles (`gtk_fixed_put`) ;
- les signatures et connexions d’événements ;
- le lexer, le parser et les erreurs de syntaxe du sous-ensemble PHP ;
- l’analyse sémantique (variable inconnue, type incompatible, arguments) ;
- l’IR (labels, sauts, temporaires) ;
- les tableaux indexés, `for`, `foreach` et l’interpolation de chaînes ;
- les tableaux associatifs `string => valeur` et leur copie ;
- `switch`, `match` et `isset` ;
- les exceptions `throw` / `try` / `catch` et leur propagation ;
- les classes, objets, propriétés, constructeurs et méthodes ;
- les closures, captures par valeur et arrow functions ;
- l’analyse de flux (affectation définie, retours exhaustifs) ;
- le runtime mémoire (racines, GC mark-sweep, libération à l’arrêt) ;
- la compilation ELF et l’exécution de programmes PHP natifs ;
- la génération et l’exécution d’une application GTK4 via `ui_*` ;
- la compilation ELF des formulaires quand `pkg-config gtk4` est disponible.

Les tests ELF de formulaire et d’UI sont ignorés sur une machine sans en-têtes
GTK4 ; la CI installe `libgtk-4-dev` pour les exécuter sous Xvfb.

## Feuille de route

| Phase | Contenu | État |
|---|---|---|
| 1 | formulaires `.form.json → C GTK4 → ELF` | livré |
| 2 | lexer, parser, AST, IR et backend C du sous-ensemble PHP | livré pour le noyau scalaires/tableaux/boucles |
| 3 | runtime natif : mémoire, UI GTK4 | mémoire livrée, UI procédurale livrée, façade objet à venir |
| 4 | modules natifs : `libcurl`, `libssh2`, SQLite, etc. | à venir |
| 5 | packaging : `--package=appimage` et `--package=flatpak` | à venir |

Le sous-ensemble doit encore couvrir l’héritage, les objets et callables en
paramètres/retours, les tableaux associatifs à clés entières et la capture par
référence. La façade objet `Cyonima\UI` sera construite au-dessus de la couche
procédurale `ui_*`. Le
compilateur reste extensible : nouveaux types, nouveaux contrôles et nouvelles
constructions s’ajoutent derrière l’AST et l’IR, sans réécrire le backend C.
