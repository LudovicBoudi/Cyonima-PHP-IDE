# Runtime (M2)

Le runtime transforme des callbacks GTK synchrones en application moderne :
tâches coopératives, sous-processus, erreurs centralisées, cycle de vie.

```
GTK (C)  ──tick 16 ms──►  Loop ──►  Scheduler (fibres)  ──►  code applicatif
                          │
                          ├──►  timers PHP
                          ├──►  Process (sous-processus)
                          └──►  Errors (report unique)
```

## Modèle d'exécution

PHP est mono-thread. Il n'y a **pas de préemption** : une tâche ne rend la main
que si elle appelle `Async::sleep()`, `Async::yield()` ou `Async::await()`.

| Travail | Outil |
|---|---|
| Attente d'I/O, animation, séquence | Fibre (`Async::run`) |
| Calcul CPU long (> 50 ms) | `Process` (sous-processus) |
| Timer ponctuel | `Loop::after()` |
| Handler de signal | fibre automatique si le signal ne retourne rien |

Une boucle CPU qui n'appelle jamais l'un des trois points de suspension fige
l'UI. C'est une limite du langage, pas du runtime : dans ce cas, isoler le
travail dans un `Process`.

## Règle R1 : une seule source GLib

`Loop` enregistre **un seul** `g_timeout_add` (16 ms) au démarrage. Les timers
applicatifs vivent dans une file PHP. C'est la conséquence directe de la mesure
du spike : chaque enregistrement de closure dans GLib fuit 200–650 octets, et
une UI active en créerait des dizaines par seconde.

## API

### `Loop`

```php
Loop::after(500, fn () => $label->text('fini'));   // timer PHP, idempotent
Loop::cancelTimer($id);
Loop::onTick($listener);                            // branché par Application
Loop::run();
Loop::quit();                                       // depuis un handler ou une fibre
```

### `Async` et `Task`

```php
$task = Async::run(function (): void {
    $label->text('chargement…');
    Async::sleep(400);                 // l'UI reste réactive
    $label->text('fini');
});

$task->then(fn () => $log('terminée'));
$task->onError(fn (Throwable $e) => $log($e->getMessage()));
$task->isDone();
```

`Async::await()` suspend la tâche courante jusqu'à la fin d'une autre tâche ou
d'un processus :

```php
$result = Async::await($otherTask);
$output = Async::await(Process::php('echo "bonjour";'))->output();
```

Une tâche démarre **immédiatement**, jusqu'à son premier point de suspension :
un handler qui ne suspend pas se comporte exactement comme avant.

### `Process`

```php
$process = Process::php('usleep(900000); echo "OK";');   // démarre tout de suite
$result  = $process->await();                            // depuis une fibre

$result->exitCode;   // 0
$result->output();   // "OK"
$result->stderr;
$result->durationMs;
```

Deux processus lancés ensemble tournent réellement en parallèle : c'est le seul
moyen d'utiliser plusieurs cœurs depuis PHP sans extension.

### `Errors`

```php
Errors::handler(function (Throwable $e, string $context): void {
    // log, dialogue, télémétrie…
});
```

Par défaut : message sur STDERR, trace complète si `CYONIMA_DEBUG=1`.

Tout converge ici : handlers de signaux, timers, tâches, ticks, complétions de
processus. Règle R2 : aucune exception ne traverse une frontière C.

### `Application`

```php
$app = new Application('com.example.app');
$app->addCss('window { background: #101418; }');   // CSS global
$app->onError($handler);

$window = $app->window('Titre', 900, 600);
$window->setChild($root)->present();

$app->run();   // retourne quand la dernière fenêtre est fermée
```

`Application::run()` installe aussi le dispatcher de signaux : les handlers de
signaux **void** (`clicked`, `activate`…) deviennent des fibres. Les signaux à
valeur de retour (`close-request`) restent synchrones pour que leur retour soit
honoré.

### `AdwApplication`

```php
$app = new AdwApplication('com.example.app');
$window = $app->window('Titre', 'sous-titre');
$window->headerEnd($bouton);
$window->content(new Clamp($page, 560));
$window->present();
$app->run();
```

`Adw.ApplicationWindow` exige un `GApplication` enregistré : le runtime appelle
`g_application_register()` (qui émet `startup`) mais **jamais**
`g_application_run()`. La boucle reste `Loop`, donc timers, fibres et
sous-processus sont identiques aux applications GTK.

`AdwWindow` fournit : `title()`, `subtitle()`, `headerStart()`, `headerEnd()`,
`content()` (enveloppé dans un `ToastOverlay`), `toast()`, `onCloseRequest()`,
`addCss()`.

### Éditeur et terminal

```php
$editor = new CodeEditor('php', $source);   // GtkSourceView 5
$editor->language('php')->scheme('Adwaita')->text($source);
$editor->charCount();
$editor->lineCount();

$terminal = new Terminal();                  // VTE
$terminal->spawn(['/bin/bash'], getcwd());
$terminal->write("php script.php\n");
$terminal->onChildExited(fn (Terminal $t, int $status) => ...);
```

`Terminal::spawn()` passe par `Runtime\Vte`, un pont FFI minimal : le typelib
expose `spawn_async` avec des `char**` et deux callbacks, hors du périmètre du
générateur. `child-exited` fournit le statut d'attente (`code << 8`), et il est
connecté en synchrone : c'est une notification courte, pas une tâche.

VTE 0.76 émet un `GLib-CRITICAL: g_utf8_make_valid` au démarrage d'un enfant.
C'est du bruit amont, sans effet sur l'exécution (vérifié : le processus fils
s'exécute et rend son code de sortie).

### Navigateur de fichiers

```php
$browser = new FileBrowser($racine, function (string $path): void {
    $editor->setText(file_get_contents($path));
});
$browser->refresh();
$browser->open($path);   // équivaut à un clic
```

Une liste plate par répertoire (navigation par activation) évite
`GtkTreeView` et ses iters. Les rows sont gardées en vie par le composant,
donc leurs handlers aussi.

### Client LSP

```php
$lsp = LspClient::start(['phpactor', 'language-server']);
$lsp->attach();                     // lecture branchée sur le tick

$lsp->on('textDocument/publishDiagnostics', function (array $params): void {
    // params['diagnostics']
});

$lsp->initialize('file://' . $racine, function (array $result): void {
    // capabilities
});
$lsp->didOpen('file:///chemin/fichier.php', 'php', $source);
```

JSON-RPC 2.0 sur stdio, framing `Content-Length`, transport non bloquant : la
boucle GTK n'est jamais bloquée. `tests/fixtures/fake_lsp.php` sert de serveur
de test reproductible ; n'importe quel serveur LSP réel peut être branché via
`CYONIMA_LSP`.

### Complétion

```php
$popup = new CompletionPopup($editor);

$editor->onKeyPressed(function (CodeEditor $e, int $keyval, int $state) use ($popup): bool {
    if (($state & 4) !== 0 && $keyval === 0x20) { /* Ctrl+Espace */ }
    // flèches / Entrée / Échap quand le popover est visible
});

$lsp->request('textDocument/completion', [
    'textDocument' => ['uri' => $uri],
    'position' => $editor->cursor(),   // ['line' => …, 'character' => …]
], function (mixed $result) use ($popup, $editor): void {
    $items = LspClient::normalizeCompletion($result);
    $popup->show($items, function (array $item) use ($editor): void {
        $editor->replacePrefix(mb_strlen($editor->wordPrefix()), $item['insertText'] ?? $item['label']);
    });
});
```

`GtkText` fournit `cursor()`, `wordPrefix()` et `replacePrefix()` en manipulant
les `GtkTextIter` via le blob aligné de 80 octets. C'est ce qui permet de
remplacer le mot en cours sans shim C.

### Débogueur Xdebug

```php
$debug = XdebugClient::listen(9003);   // l'IDE écoute, Xdebug se connecte
$debug->attach();

$debug->on('connect', function (string $fileUri, ?string $ideKey): void { /* … */ });
$debug->on('break', function (string $fileUri, int $line): void { /* pause */ });
$debug->on('stop', function (): void { /* session terminée */ });

$debug->setBreakpoint('file:///chemin/script.php', 12);
$debug->stepOver();
$debug->run();
```

Protocole DBGp : paquets XML terminés par un octet NUL, transport non
bloquant branché sur le tick. L'IDE pose les points d'arrêt, reçoit les pauses
(`status="break"` + `<xdebug:message filename lineno>`), puis reprend ou
avance. `tests/fixtures/fake_xdebug.php` simule un moteur pour les tests.

## Pièges

| Piège | Conséquence | Solution |
|---|---|---|
| Boucle CPU sans `Async::yield()` | UI figée | `Process` |
| `Async::sleep()` hors tâche | `LogicException` | envelopper dans `Async::run()` |
| `Loop::after()` sans `Loop::run()` | jamais exécuté | normal, les timers vivent dans la boucle |
| Fermer la dernière fenêtre avec des tâches en cours | tâches abandonnées | prévoir un `onQuit` si nettoyage nécessaire |
| Handler de signal à retour booléen | reste synchrone | voulu : le retour doit être immédiat |

## Tests

`tests/run.php` section 5 couvre : ordre des timers, sommeil parallèle de deux
fibres, `await`, exception de tâche, deux sous-processus concurrents (250 ms
chacun terminés en < 800 ms), suspension dans un handler de signal, et cycle de
vie applicatif complet.
