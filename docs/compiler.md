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
| Tableaux | indexés homogènes, `[...]`, `array(...)`, `$a[i]`, `$a[]`, `count` |
| Opérateurs | `+ - * / % .`, comparaisons, `===`, `!==`, `&&`, `\|\|`, `!` |
| Contrôle | `if`, `elseif`, `else`, `while`, `for`, `foreach` |
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

### Limites explicites

Les constructions suivantes sont refusées avec un message et un numéro de
ligne :

- tableaux associatifs, tableaux imbriqués, tableaux passés en paramètre ou
  retournés par une fonction ;
- `switch`, `match` ;
- objets, classes, interfaces, appels de méthodes et propriétés ;
- closures et fonctions anonymes ;
- `eval`, réflexion, `include`/`require`, autoload ;
- références (`&$x`), `++`/`--`, opérateurs combinés (`+=`) ;
- exceptions, générateurs, fibres ;
- index hors limites : le runtime s’arrête avec un message explicite.

Les tableaux sont **homogènes** (`int`, `float`, `bool` ou `string`) et
indexés par entiers. Une affectation de tableau crée une copie, comme en PHP
pour les affectations par valeur.

### Modèle mémoire

Le runtime natif (`runtime/native/cyonima_runtime.h`) centralise toutes les
allocations :

- `cy_alloc` / `cy_free` remplacent `malloc` et `realloc` dans le code généré ;
- chaque fonction pousse une **frame de racines** contenant ses variables et
  temporaires de type chaîne ou tableau ;
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
référencés par des handles `int` (1..256). Il crée une `GtkApplication`, les
fenêtres, les boîtes et les contrôles, puis relie les signaux GTK aux
fonctions PHP compilées.

### Fonctions UI

| Fonction | Rôle |
|---|---|
| `ui_init(string $appId)` | crée l’application GTK |
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
| `ui_on_click(int $button, string $handler, int $data = 0)` | clic |
| `ui_on_activate(int $entry, string $handler, int $data = 0)` | validation d’entrée |
| `ui_after(int $ms, string $handler, int $data = 0)` | timer unique |
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

### Limites de cette première couche

- API procédurale : pas encore de classes, objets, méthodes ni closures.
- Pas d’accès aux variables globales depuis un gestionnaire ; les handles sont
  transmis explicitement via le troisième argument.
- Widgets disponibles : fenêtre, boîte, libellé, bouton, entrée.
- Une seule application GTK par programme, 256 widgets maximum.
- Pas encore de CSS, de menus, de listes ni de modèles de données.
- La compilation ELF d’une application UI exige `libgtk-4-dev`.

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
- les tableaux, `for`, `foreach` et l’interpolation de chaînes ;
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

Le sous-ensemble doit encore couvrir les objets, classes, closures, `switch`,
`match`, les exceptions et les tableaux associatifs. La façade objet
`Cyonima\UI` sera construite au-dessus de la couche procédurale `ui_*`. Le
compilateur reste extensible : nouveaux types, nouveaux contrôles et nouvelles
constructions s’ajoutent derrière l’AST et l’IR, sans réécrire le backend C.
