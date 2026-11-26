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
| Types | `int`, `float`, `bool`, `string`, `void`, `array` annoté, `?string`, `?Classe`, `?Interface`, `?callable` |
| Variables | `$nom`, affectation simple |
| Littéraux | entiers, flottants, chaînes, `true`, `false`, `null` |
| Chaînes | concaténation `.`, interpolation `"Bonjour $nom"` et `"{$nom}"` |
| Tableaux indexés | homogènes scalaires ou objets, `[...]`, `array(...)`, `$a[i]`, `$a[]`, `count` |
| Tableaux associatifs | `['clé' => valeur]`, `$a['clé']`, `foreach` clé/valeur, `count` |
| Signatures de tableaux | `array<T>`, `array<string,T>`, `T` scalaire ou classe/interface |
| Opérateurs | `+ - * / % .`, comparaisons, `===`, `!==`, `&&`, `\|\|`, `!` |
| Contrôle | `if`, `elseif`, `else`, `while`, `for`, `foreach`, `switch` (fall-through, `break`), `match` |
| Vérification | `isset($a[$clé])` |
| Exceptions | `throw`, `try` / `catch (string $e)`, propagation entre fonctions |
| Classes | `class`, propriétés typées, `new`, `$this`, méthodes, `__construct` |
| Héritage | `class X extends Y`, propriétés/méthodes héritées, override, dispatch dynamique, retours covariants |
| Interfaces | `interface`, `implements`, `extends` multiple, dispatch via table d’interfaces |
| Abstrait | `abstract class`, `abstract function`, classes non instanciables |
| Parent | `parent::__construct(...)`, `parent::méthode(...)` |
| Statique | `static` propriétés/méthodes, `Classe::méthode()`, `Classe::$propriété`, `self::` |
| Objets | objets en paramètres, retours et propriétés |
| Closures | `function (...) use (...)` / `use (&$x)` et `fn (...) => ...`, appels `$fn(...)` |
| Fonctions | déclaration typée, paramètres, retour, appels |
| Sortie | `echo` multi-arguments |
| Fonctions natives | `strlen`, `intval`, `floatval`, `strval`, `abs`, `count` |
| Modules natifs | SQLite, libcurl et libssh2 (`sqlite_*`, `curl_*`, `ssh2_*`) |
| Packaging natif | `--package=appimage\|flatpak` |
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

- propriétés typées `int`, `float`, `bool`, `string`, callable ou classe,
  initialisées à `0` / `false` / `""` / `NULL` ;
- constructeur `__construct` optionnel, appelé par `new Class(...)` ; un
  constructeur peut être hérité ;
- méthodes compilées en fonctions C avec un paramètre `$this` ;
- accès et affectation `$objet->propriété`, appels `$objet->méthode()` ;
- objets en paramètres, retours et propriétés ;
- affectation d’objet = partage de référence, comme en PHP ;
- GC : une fonction de marquage par classe protège les propriétés chaîne,
  tableau/table, objet et closure.

### Héritage

```php
class Animal {
    public string $name;

    public function __construct(string $name) {
        $this->name = $name;
    }

    public function speak(): string {
        return '...';
    }
}

class Dog extends Animal {
    public function speak(): string {
        return 'Woof';
    }
}

function describe(Animal $animal): string {
    return $animal->name . ': ' . $animal->speak();
}

echo describe(new Dog('Rex')); // Rex: Woof
```

- propriétés et méthodes héritées ;
- surcharge de méthode avec paramètres identiques ;
- **retour covariant** : une méthode enfant peut retourner une sous-classe ou
  une classe implémentant l’interface déclarée par le parent ;
- les types scalaires et callables restent invariants ;
- dispatch dynamique via une vtable par classe ;
- un enfant est assignable à un paramètre/retour du parent ;
- `$this` garde le type statique de la classe où la méthode est déclarée ;
- `parent::__construct(...)` et `parent::méthode(...)` appellent
  l’implémentation parente directe.

### Interfaces et classes abstraites

```php
interface Speaker {
    public function speak(): string;
}

interface Named extends Speaker {
    public function name(): string;
}

abstract class Shape implements Speaker {
    public abstract function area(): float;

    public function speak(): string {
        return 'shape';
    }
}

class Square extends Shape {
    public float $side;

    public function __construct(float $side) {
        $this->side = $side;
    }

    public function area(): float {
        return $this->side * $this->side;
    }
}

function talk(Speaker $speaker): string {
    return $speaker->speak();
}
```

- `interface` + `implements`, plusieurs interfaces possibles ;
- `interface B extends A` et `interface C extends A, B`, avec héritage
  transitif ;
- une classe qui implémente une interface enfant est aussi dispatchable via
  toutes ses interfaces parentes ;
- les méthodes héritées doivent être implémentées par les classes concrètes ;
- une interface peut redéclarer une méthode héritée avec les mêmes paramètres
  et un retour covariant ; un retour plus large est refusé ;
- les collisions entre deux interfaces parentes sont refusées, sauf si un
  retour est un sous-type de l’autre : le type le plus précis est conservé,
  quel que soit l’ordre des parents ;
- méthodes d’interface sans corps ;
- `abstract class` non instanciable ;
- `abstract function` sans corps, obligatoirement implémentée par une classe
  concrète ;
- une classe abstraite peut laisser des méthodes d’interface non implémentées ;
- dispatch d’interface via `CyObjectHeader` et la table `cy_ifaces` ;
- `new` sur une interface ou une classe abstraite est refusé.

### Membres statiques

```php
class Counter {
    public static int $count;
    public static string $label;

    public static function increment(): int {
        self::$count = self::$count + 1;
        return self::$count;
    }
}

Counter::$label = 'n';
echo Counter::increment(); // 1
echo Counter::$count;      // 1
```

- propriétés `static` typées, stockées dans une globale C par classe
  propriétaire ;
- méthodes `static` compilées sans paramètre `$this` ;
- appels `Classe::méthode(...)`, `self::méthode(...)` ;
- accès et affectation `Classe::$propriété`, `self::$propriété` ;
- une propriété statique héritée est partagée avec la classe qui la déclare ;
- une méthode statique héritée peut être surchargée ; `Classe::méthode()`
  appelle l’implémentation de cette classe, `self::` reste lié à la classe
  d’écriture ;
- `$this` est refusé dans une méthode statique ;
- les propriétés statiques objet, chaîne, tableau/table et closure sont
  enregistrées comme racines GC permanentes ;
- les méthodes statiques ne figurent pas dans les vtables d’instance ;
- refus explicites : `static::`, `parent::` statique, méthode statique
  abstraite, membre statique d’interface, appel statique d’une méthode
  d’instance et appel via `->` d’une méthode statique.

Un exemple complet est fourni dans
[`examples/native-static.php`](../examples/native-static.php).

Un exemple des interfaces, de leur héritage et des classes abstraites est
fourni dans [`examples/native-interfaces.php`](../examples/native-interfaces.php).

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
- `function (...) use (&$x)` capture par **référence** : la closure et le
  scope extérieur partagent la même variable ;
- `fn (...) => ...` capture automatiquement les variables libres par valeur ;
- type interne `callable(int):int`, `callable():void`, etc. ;
- appels via `$fn(...)` ;
- captures scalaires, chaînes, tableaux, tables associatives, objets et
  closures ; les captures par valeur de tableaux et tables sont copiées à la
  création ;
- une capture par référence utilise une **cellule GC** : les écritures de la
  closure sont visibles à l’extérieur et réciproquement, y compris après
  retour de la fonction ou entre deux callbacks GTK ;
- l’état capturé persiste entre les appels d’une même closure ;
- codegen : structure `CyClosureData_N`, fonction `cy_closure_N`, champ
  `invoke`, champ pointeur pour chaque capture par référence et fonction de
  marquage GC des captures et cellules.

Une closure anonyme doit déclarer son type de retour. Une arrow function peut
l’omettre : il est inféré depuis l’expression.

Un exemple complet est fourni dans
[`examples/native-closures.php`](../examples/native-closures.php).

### Modules natifs

Un module natif fournit des fonctions C liées au binaire, avec une signature
PHP typée. Trois modules sont disponibles : `sqlite`, `curl` et `ssh2`.

- activation automatique dès qu’une fonction du module est appelée ;
- activation explicite via `--module=sqlite`, `--module=curl`,
  `--module=ssh2` (répétable) ;
- handles opaques de type `int`, `0` en cas d’échec ;
- résultats texte copiés dans le tas managé ;
- conflit avec une fonction utilisateur refusé ;
- module indisponible → erreur explicite ;
- options de liaison via `pkg-config`, affichées en `--emit=c`
  (`Link : ...`).

#### SQLite

```php
$db = sqlite_open(':memory:');

if ($db === 0) {
    throw 'ouverture impossible';
}

sqlite_exec($db, 'CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');
sqlite_exec($db, "INSERT INTO items (name) VALUES ('alpha')");

echo sqlite_query_int($db, 'SELECT count(*) FROM items');
echo sqlite_query_string($db, 'SELECT name FROM items WHERE id = 1');

sqlite_close($db);
```

Fonctions : `sqlite_open`, `sqlite_close`, `sqlite_exec`, `sqlite_error`,
`sqlite_last_id`, `sqlite_changes`, `sqlite_query_int`,
`sqlite_query_float`, `sqlite_query_string`, `sqlite_query_bool`.
Liaison : `pkg-config sqlite3`.

#### libcurl

```php
$curl = curl_init('https://example.com');
curl_set_method($curl, 'GET');
curl_set_header($curl, 'Accept: text/html');

if (curl_perform($curl)) {
    echo curl_status($curl);
    echo curl_body($curl);
} else {
    echo curl_error($curl);
}

curl_close($curl);
```

Fonctions : `curl_init`, `curl_set_method`, `curl_set_body`,
`curl_set_header`, `curl_perform`, `curl_status`, `curl_body`, `curl_error`,
`curl_close`. Méthodes `GET`, `POST` et méthodes personnalisées. Liaison :
`pkg-config libcurl`.

#### libssh2

```php
$ssh = ssh2_connect('example.org', 22);

if ($ssh === 0) {
    echo ssh2_error(0);
} else {
    if (ssh2_auth_password($ssh, 'demo', 'secret')) {
        echo ssh2_exec($ssh, 'uname -a');
        echo ssh2_exit_status($ssh);
    } else {
        echo ssh2_error($ssh);
    }

    ssh2_disconnect($ssh);
}
```

Fonctions : `ssh2_connect`, `ssh2_auth_password`, `ssh2_exec`,
`ssh2_exit_status`, `ssh2_error`, `ssh2_disconnect`. Liaison :
`pkg-config libssh2`.

L’architecture `Cyonima\Compiler\Native\NativeModule` reste extensible : un
module déclare ses signatures, ses includes, son code C et ses options de
liaison.

Exemples complets : [`examples/native-sqlite.php`](../examples/native-sqlite.php),
[`examples/native-curl.php`](../examples/native-curl.php),
[`examples/native-ssh.php`](../examples/native-ssh.php).

### Packaging direct

Le compilateur peut produire directement une application autonome :

```bash
# AppImage native
bin/cyonima-compile --project=./mon-projet \
  --php=src/main.php --package=appimage

# Flatpak natif
bin/cyonima-compile --project=./mon-projet \
  --php=src/main.php --package=flatpak
```

- métadonnées lues dans `.cyonima/project.json` (`name`, `appId`, `version`) ;
- sortie `dist/<slug>-<version>-x86_64.AppImage` ou `.flatpak` ;
- `--output=PATH` remplace le chemin du paquet ;
- l’AppImage embarque le binaire, ses bibliothèques non système et GTK4/Adw
  lorsqu’il en dépend, avec schémas GSettings, loaders GdkPixbuf et icônes ;
- le Flatpak utilise `org.gnome.Platform//49` et embarque les bibliothèques
  absentes du runtime, comme `libssh2` ;
- aucun interpréteur PHP n’est embarqué : le binaire est déjà natif ;
- scripts : `packaging/appimage/build-native-project.sh` et
  `packaging/flatpak/build-native-project-flatpak.sh`.

Vérifications effectuées :

```text
hello native
native package
packaged
native flatpak
clicked
```

Les deux formats ont aussi été validés avec une application GTK4 native.

### Limites explicites

Les constructions suivantes sont refusées avec un message et un numéro de
ligne :

- clés associatives entières (les tables associatives sont `string => valeur`) ;
- tableaux imbriqués, tableaux dans les signatures de closures et de
  callables ;
- `break`/`continue` dans les boucles (seul `break` de `switch` est supporté) ;
- `match` sans `default` et `isset` avec plusieurs arguments ;
- héritage multiple de classes, application de la visibilité (les mots-clés
  sont acceptés mais ignorés) ;
- `static::`, `parent::` statique, méthodes statiques abstraites ou
  d’interface, valeurs par défaut de propriétés statiques ;
- `?int`, `?float`, `?bool`, `?array`, `array<?T>` et les signatures de
  closures/callables nullables ;
- appel explicite à `__construct` hors `parent::`, contravariance des
  paramètres, valeurs par défaut de propriétés, `clone`, `instanceof` ;
- capture de `$this`, closures `static`, paramètres `&$x` ;
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

Les tableaux indexés sont **homogènes** (`int`, `float`, `bool`, `string` ou
une classe/interface). Les tables associatives utilisent des clés `string` et
des valeurs homogènes. Une affectation de tableau ou de table associative crée
une copie, comme en PHP pour les affectations par valeur.

Un tableau vide `[]` est raffiné par le contexte lorsqu’il est affecté à une
variable annotée, passé à un paramètre typé ou retourné par une fonction
typée. Sinon, il doit être raffiné par un premier ajout indexé (`$a[] =`) ou
une première clé (`$a['clé'] =`) avant tout autre usage : sa nature (indexée
ou associative) et son type de valeur doivent être connus à la compilation.

### Tableaux en signatures

Le code source reste du PHP valide : le type PHP est `array`, et le type des
éléments est précisé par une annotation PHPDoc.

```php
/**
 * @param array<int> $values
 */
function total(array $values): int {
    $sum = 0;
    foreach ($values as $value) {
        $sum = $sum + $value;
    }
    return $sum;
}

/**
 * @param array<string,int> $table
 */
function lookup(array $table, string $key): int {
    return $table[$key];
}

/**
 * @return array<int>
 */
function build(): array {
    return [1, 2, 3];
}
```

```php
class Bag {
    /** @var array<int> */
    public array $items;
}
```

`T` peut aussi être une classe ou une interface :

```php
class User {
    public string $name;
}

/**
 * @param array<User> $users
 * @return array<string,User>
 */
function indexByName(array $users): array {
    $table = [];

    foreach ($users as $user) {
        $table[$user->name] = $user;
    }

    return $table;
}
```

- `array<T>` : tableau indexé homogène (`T` scalaire, classe ou interface) ;
- `array<string,T>` : table associative `string => T` ;
- annotations acceptées : `@param`, `@return`, `@var` ;
- l’annotation est obligatoire dans une signature : `array` seul est refusé ;
- une annotation ne raffine que le type PHP `array` ;
- affectation, passage en argument et retour créent une **copie** : une
  mutation dans la fonction ne modifie pas le tableau de l’appelant ;
- une propriété tableau est initialisée à un tableau vide par `new` ;
- les collections d’objets sont marquées par le GC, y compris dans les
  propriétés, propriétés statiques et closures ;
- `array<Enfant>` est assignable à `array<Parent>` ou `array<Interface>` ;
- les tableaux de tableaux restent refusés, ainsi que les éléments
  `callable` et les annotations dans les closures et les callables.

Un exemple complet est fourni dans
[`examples/native-object-collections.php`](../examples/native-object-collections.php).

### Types nullables

Les types représentés par un pointeur peuvent être nullables :

```php
function find(bool $present): ?User {
    if (!$present) {
        return null;
    }

    return new User('alice');
}

function greet(?string $name): string {
    if ($name === null) {
        return 'nobody';
    }

    return 'hello ' . $name;
}
```

- supportés : `?string`, `?Classe`, `?Interface`, `?callable(signature)` ;
- utilisables en paramètres, retours, propriétés instance/statiques et
  captures de closures ;
- `null` est assignable/retournable/passable vers un type nullable ;
- comparaisons `=== null`, `!== null`, `== null`, `!= null` ;
- comparaison de chaîne nullable avec une chaîne (`===`, `!==`, `==`, `!=`)
  avec contrôle de null ;
- concaténation et `echo` d’une chaîne nullable : `null` devient vide ;
- retour covariant : `Dog` → `?Animal`, `?Dog` → `?Animal` ;
- une valeur non nullable ne peut pas être affectée à un nullable sans
  contrôle ; un nullable ne peut pas être affecté à un non-nullable ;
- l’accès à une propriété, l’appel de méthode ou l’invocation d’une closure
  nullable compile avec une **garde runtime** : si la valeur est `null`, une
  exception `string` est levée et peut être capturée ;
- les propriétés nullables sont initialisées à `NULL`, comme les variables
  locales et les racines GC.

Limites actuelles :

- `?int`, `?float`, `?bool` ne sont pas supportés (représentation optionnelle
  non encore définie) ;
- `?array` et les éléments de tableau nullables (`array<?User>`) sont refusés ;
- les paramètres/retours de closures et les callables ne peuvent pas encore
  être nullables ;
- une variable locale doit provenir d’une expression nullable typée : on ne
  peut pas encore écrire `$x = null` puis l’affecter plus tard sans type
  préalable.

Un exemple complet est fourni dans
[`examples/native-nullable.php`](../examples/native-nullable.php).

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

$box->append($label);
$box->append($button);
$window->setChild($box);

$button->onClicked(fn () => $label->setText('Cliqué !'));

$window->present();
$app->run();
```

- `Widget` est la classe de base des widgets, avec le handle et
  `setVisible(bool)` ;
- les callbacks sont des closures `callable():void` enregistrées via
  `ui_on_click_closure` et maintenues en vie par les racines GC ;
- fermer la fenêtre arrête la boucle ;
- un exemple complet est fourni dans
  [`examples/native-ui-object.php`](../examples/native-ui-object.php).

### Limites de cette première couche

- façade objet et composition par objets disponibles ; widgets limités à la
  fenêtre, la boîte, le libellé, le bouton et l’entrée ;
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
| `--module=NAME` | active un module natif (`sqlite`, `curl`, `ssh2`), répétable |
| `--package=appimage\|flatpak` | compile puis empaquette directement l’application |
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
- l’héritage, la covariance des retours, les vtables, les objets en
  paramètres/retours et propriétés ;
- les interfaces, leur héritage multiple, les classes abstraites et appels
  `parent::` ;
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
| 2 | lexer, parser, AST, IR et backend C du sous-ensemble PHP | livré |
| 3 | runtime natif : mémoire, UI GTK4 | livré, façade objet incluse |
| 4 | modules natifs : SQLite, `libcurl`, `libssh2` | livré |
| 5 | packaging : `--package=appimage` et `--package=flatpak` | livré |

La façade objet
`Cyonima\UI` repose sur l’héritage, les interfaces, les membres statiques et la
composition par objets. Le
compilateur reste extensible : nouveaux types, nouveaux contrôles et nouvelles
constructions s’ajoutent derrière l’AST et l’IR, sans réécrire le backend C.
