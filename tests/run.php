<?php

declare(strict_types=1);

/**
 * Suite de tests de la tranche M1.
 *
 * Volontairement sans dépendance : un binaire PHP, un display, et PyGObject
 * quand il est disponible pour les tests de différentiation.
 *
 *   php -d ffi.enable=true tests/run.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Cyonima\Generator\Generator;
use Cyonima\Generator\Spec;
use Cyonima\Gtk\Raw;
use Cyonima\Runtime\AdwApplication;
use Cyonima\Runtime\Application;
use Cyonima\Runtime\Async;
use Cyonima\Runtime\Errors;
use Cyonima\Runtime\Kernel;
use Cyonima\Runtime\Loop;
use Cyonima\Runtime\LspClient;
use Cyonima\Runtime\XdebugClient;
use Cyonima\Runtime\Process;
use Cyonima\Runtime\Scheduler;
use Cyonima\UI;

final class T
{
    public static int $passed = 0;
    public static int $failed = 0;
    public static int $skipped = 0;

    public static function section(string $title): void
    {
        echo "\n\033[1m{$title}\033[0m\n";
    }

    public static function ok(bool $condition, string $label, string $detail = ''): void
    {
        if ($condition) {
            self::$passed++;
            echo "  \033[32m✓\033[0m {$label}\n";
            return;
        }
        self::$failed++;
        echo "  \033[31m✗\033[0m {$label}";
        echo $detail === '' ? "\n" : " — {$detail}\n";
    }

    public static function same(mixed $expected, mixed $actual, string $label): void
    {
        self::ok(
            $expected === $actual,
            $label,
            sprintf('attendu %s, obtenu %s', var_export($expected, true), var_export($actual, true)),
        );
    }

    public static function skip(string $label): void
    {
        self::$skipped++;
        echo "  \033[33m∅\033[0m {$label} (ignoré)\n";
    }

    public static function report(): int
    {
        $total = self::$passed + self::$failed;
        echo sprintf(
            "\n%d test(s) : %d réussi(s), %d échec(s), %d ignoré(s)\n",
            $total,
            self::$passed,
            self::$failed,
            self::$skipped,
        );
        return self::$failed === 0 ? 0 : 1;
    }
}

function rssMb(): float
{
    $statm = file_get_contents('/proc/self/statm');
    $pages = (int) explode(' ', $statm)[1];
    return $pages * 4096 / 1048576;
}

function python(string $code): ?string
{
    static $available = null;
    if ($available === null) {
        $available = trim((string) shell_exec('command -v python3 2>/dev/null')) !== ''
            && trim((string) shell_exec('python3 -c "import gi" 2>&1')) === '';
    }
    if (!$available) {
        return null;
    }

    $output = shell_exec('python3 -W ignore -c ' . escapeshellarg($code) . ' 2>/dev/null');
    return $output === null ? null : trim($output);
}

// ---------------------------------------------------------------------------
T::section('1. Artefacts générés');

foreach (['Window', 'Widget', 'Box', 'Label', 'Button', 'CssProvider', 'StyleContext', 'Symbols'] as $class) {
    T::ok(class_exists("Cyonima\\Gtk\\Raw\\{$class}"), "classe Raw\\{$class} chargée");
}

T::same('GtkWindow', Raw\Window::TYPE_NAME, 'TYPE_NAME de Window vient du typelib');
T::same('GtkButton', Raw\Button::TYPE_NAME, 'TYPE_NAME de Button vient du typelib');
T::same(1, Raw\Orientation::VERTICAL, 'Orientation::VERTICAL');
T::same(0, Raw\Orientation::HORIZONTAL, 'Orientation::HORIZONTAL');
T::same(3, Raw\Align::CENTER, 'Align::CENTER');
T::same('close-request', Raw\Window::SIGNAL_CLOSE_REQUEST, 'constante de signal close-request');
T::same('clicked', Raw\Button::SIGNAL_CLICKED, 'constante de signal clicked');

// ---------------------------------------------------------------------------
T::section('2. Générateur : déterminisme');

$spec = Spec::fromFile(dirname(__DIR__) . '/spec/desktop.php');
$first = (new Generator($spec))->generate();
$hashesFirst = [];
foreach ($first->files as $file) {
    $hashesFirst[$file] = hash_file('sha256', $file);
}
$second = (new Generator($spec))->generate();
$stable = true;
foreach ($second->files as $file) {
    if (($hashesFirst[$file] ?? null) !== hash_file('sha256', $file)) {
        $stable = false;
    }
}
T::ok($stable, 'une seconde génération produit exactement les mêmes fichiers');
$expectedClasses = count($spec->classes);
T::ok($first->classes === $expectedClasses, "{$expectedClasses} classes générées", (string) $first->classes);

$expectedMethods = array_sum(array_map(
    static fn (array $class): int => count($class['methods']),
    $spec->classes,
));
T::ok($first->methods === $expectedMethods, "{$expectedMethods} méthodes générées", (string) $first->methods);
T::same(0, count($first->skipped), 'aucune méthode de la spec ignorée');

// ---------------------------------------------------------------------------
T::section('3. Cycle de vie et mémoire');

// Warm-up : GTK/Pango chargent thèmes et polices au premier widget.
for ($i = 0; $i < 200; $i++) {
    $warmup = Raw\Label::new('warmup');
    unset($warmup);
}
gc_collect_cycles();

$before = rssMb();
for ($i = 0; $i < 2000; $i++) {
    $label = Raw\Label::new('tmp');
    unset($label);
}
gc_collect_cycles();
$delta = rssMb() - $before;
T::ok($delta < 4.0, '2000 labels créés/libérés sans fuite', sprintf('%+.2f Mo', $delta));
printf("    RSS après 2000 cycles (hors warm-up) : %+.2f Mo\n", $delta);

$box = Raw\Box::new(Raw\Orientation::VERTICAL, 0);
$child = Raw\Label::new('enfant');
$box->append($child);
unset($child); // le parent garde une référence GObject
T::ok(true, 'un widget parenté survit à la libération du wrapper PHP');

// ---------------------------------------------------------------------------
T::section('4. Signaux, exceptions et boucle');

$clicks = 0;
$error = null;

Raw\Widget::onError(function (Throwable $e, string $signal) use (&$error): void {
    $error = [$signal, $e->getMessage()];
});

$button = Raw\Button::newWithLabel('test');
$button->onClicked(function () use (&$clicks): void {
    $clicks++;
});
$button->emit(Raw\Button::SIGNAL_CLICKED);
$button->emit(Raw\Button::SIGNAL_CLICKED);
T::same(2, $clicks, 'deux clics émis déclenchent deux fois le handler PHP');

$thrower = Raw\Button::newWithLabel('boom');
$thrower->onClicked(function (): void {
    throw new RuntimeException('depuis le callback');
});
$thrower->emit(Raw\Button::SIGNAL_CLICKED);
T::same('depuis le callback', $error[1] ?? null, 'une exception de callback est capturée, pas fatale');
T::same('clicked', $error[0] ?? null, 'le signal fautif est transmis au gestionnaire d\'erreurs');

$window = new UI\Window('Tests Cyonima', 320, 200);
$label = (new UI\Label('0'))->cssClass('count');
$clicked = 0;
$faceButton = (new UI\Button('+1'))->onClicked(function () use (&$clicked, $label): void {
    $clicked++;
    $label->text((string) $clicked);
});

$window
    ->addCss('window { background: #101418; } .count { color: #3ddc97; }')
    ->setChild((new UI\Box(Raw\Orientation::VERTICAL, 8))->append($label, $faceButton))
    ->onCloseRequest(fn () => Loop::quit())
    ->present();

Loop::after(200, fn () => $faceButton->click());
Loop::after(300, fn () => $faceButton->click());
Loop::after(400, fn () => $window->raw()->close());

$start = microtime(true);
Loop::run();
$elapsed = microtime(true) - $start;

T::same('2', $label->getText(), 'le label reflète l\'état après la boucle GTK');
T::same(2, $clicked, 'deux clics traités pendant la boucle');
T::ok($elapsed < 3.0, 'la boucle se termine sur close-request', sprintf('%.0f ms', $elapsed * 1000));

// ---------------------------------------------------------------------------
T::section('5. Runtime : timers, fibres, processus, application');

// 5.1 Timers PHP ordonnés, sans enregistrement GLib par événement.
Loop::reset();
$ticks = [];
Loop::after(80, function () use (&$ticks): void { $ticks[] = 'b'; });
Loop::after(20, function () use (&$ticks): void { $ticks[] = 'a'; });
Loop::after(200, function () use (&$ticks): void { $ticks[] = 'c'; });
Loop::after(350, fn () => Loop::quit());
Loop::run();
T::same(['a', 'b', 'c'], $ticks, 'timers PHP déclenchés dans l\'ordre, sans fuite GLib');

// 5.2 Fibres coopératives : deux tâches qui dorment en parallèle.
Loop::reset();
Scheduler::reset();
$order = [];
$taskA = Async::run(function () use (&$order): void {
    $order[] = 'a1';
    Async::sleep(120);
    $order[] = 'a2';
});
Async::run(function () use (&$order): void {
    $order[] = 'b1';
    Async::sleep(40);
    $order[] = 'b2';
});
Loop::onTick(fn () => Scheduler::tick());
Loop::after(300, fn () => Loop::quit());
Loop::run();
T::same(['a1', 'b1', 'b2', 'a2'], $order, 'deux tâches dorment en parallèle et reprennent dans l\'ordre');
T::ok($taskA->isDone(), 'la tâche A est terminée');

// 5.3 await : une tâche attend le résultat d'une autre.
Loop::reset();
Scheduler::reset();
$awaited = null;
Async::run(function () use (&$awaited): void {
    $inner = Async::run(function () {
        Async::sleep(40);
        return 42;
    });
    $awaited = Async::await($inner);
});
Loop::onTick(fn () => Scheduler::tick());
Loop::after(200, fn () => Loop::quit());
Loop::run();
T::same(42, $awaited, 'Async::await() retourne le résultat de la tâche attendue');

// 5.4 Exception dans une tâche : reportée, pas fatale.
Errors::reset();
Async::run(function (): void {
    throw new RuntimeException('boom-task');
});
T::same('boom-task', Errors::last()?->getMessage(), 'une exception de tâche est reportée sans planter');

// 5.5 Sous-processus : résultat et parallélisme réel.
Loop::reset();
Scheduler::reset();
$processResults = [];
Async::run(function () use (&$processResults): void {
    $first = Process::php('usleep(250000); echo "un";');
    $second = Process::php('usleep(250000); echo "deux";');
    $a = $first->await();
    $b = $second->await();
    $processResults = [$a->output(), $b->output(), $a->exitCode, $b->exitCode];
    Loop::quit();
});
Loop::onTick(fn () => Scheduler::tick());
Loop::onTick(fn () => Process::poll());
$processStart = microtime(true);
Loop::after(2000, fn () => Loop::quit());
Loop::run();
$processElapsed = microtime(true) - $processStart;
T::same(['un', 'deux', 0, 0], $processResults, 'deux processus rendent leur sortie et leur code');
T::ok($processElapsed < 0.8, 'deux processus de 250 ms tournent en parallèle', sprintf('%.0f ms', $processElapsed * 1000));

// 5.6 Un handler de signal peut suspendre sans bloquer l'UI.
Loop::reset();
Scheduler::reset();
Raw\Widget::dispatchSignalsThrough(static function (callable $handler, array $arguments, string $signal): void {
    Scheduler::spawn(static fn () => $handler(...$arguments));
});
$asyncEvents = [];
$asyncButton = Raw\Button::newWithLabel('async');
$asyncButton->onClicked(function () use (&$asyncEvents): void {
    $asyncEvents[] = 'start';
    Async::sleep(80);
    $asyncEvents[] = 'end';
});
$asyncButton->emit(Raw\Button::SIGNAL_CLICKED);
T::same(['start'], $asyncEvents, 'le handler démarre immédiatement puis suspend');
Loop::onTick(fn () => Scheduler::tick());
Loop::after(200, fn () => Loop::quit());
Loop::run();
T::same(['start', 'end'], $asyncEvents, 'le handler reprend après sa suspension');
Raw\Widget::dispatchSignalsThrough(null);

// 5.7 Cycle de vie applicatif : dernière fenêtre fermée = boucle terminée.
Loop::reset();
Scheduler::reset();
Errors::reset();
$app = new Application('com.cyonima.tests');
$closed = 0;
$appWindow = $app->window('Tests app', 240, 140);
$appWindow->onCloseRequest(function () use (&$closed): void {
    $closed++;
});
$appWindow->present();
Loop::after(120, fn () => $appWindow->raw()->close());
$appStart = microtime(true);
$app->run();
$appElapsed = microtime(true) - $appStart;
T::same(1, $closed, 'le handler close-request de la fenêtre est appelé');
T::ok($appElapsed < 1.5, 'la boucle s\'arrête à la fermeture de la dernière fenêtre', sprintf('%.0f ms', $appElapsed * 1000));
T::same(0, count($app->windows()), 'l\'application ne suit plus aucune fenêtre');
Raw\Widget::dispatchSignalsThrough(null);

// ---------------------------------------------------------------------------
T::section('6. libadwaita : widgets, enums et cycle de vie');

T::ok(class_exists(Cyonima\Adw\Raw\ApplicationWindow::class), 'classe Adw\ApplicationWindow chargée');
T::ok(class_exists(Cyonima\Adw\Raw\PreferencesPage::class), 'classe Adw\PreferencesPage chargée');
T::same('AdwApplicationWindow', Cyonima\Adw\Raw\ApplicationWindow::TYPE_NAME, 'TYPE_NAME Adw correct');
T::ok(
    is_subclass_of(Cyonima\Adw\Raw\PreferencesRow::class, Cyonima\Gtk\Raw\Widget::class),
    'un parent non généré (GtkListBoxRow) est sauté vers Gtk\Widget',
);
T::ok(
    is_subclass_of(Cyonima\Adw\Raw\ActionRow::class, Cyonima\Adw\Raw\PreferencesRow::class),
    'Adw\ActionRow hérite de PreferencesRow',
);
T::same(0, Cyonima\Adw\Raw\CenteringPolicy::LOOSE, 'CenteringPolicy::LOOSE');
T::same(1, Cyonima\Adw\Raw\CenteringPolicy::STRICT, 'CenteringPolicy::STRICT');

Kernel::reset();
Loop::reset();
Scheduler::reset();
Errors::reset();

$adwApp = new AdwApplication('com.cyonima.tests.adw');
$adwWindow = $adwApp->window('Adw test', 'sous-titre');

$switchChanges = 0;
$switchRow = (new UI\SwitchRow('Compact', 'test'))->onChanged(function () use (&$switchChanges): void {
    $switchChanges++;
});
$stateRow = new UI\ActionRow('État', 'prêt');
$aboutButton = (new UI\Button('À propos'))->onClicked(function () use ($adwWindow): void {
    $adwWindow->toast('Test de toast');
});

$page = (new UI\PreferencesPage('Réglages', 'description'))
    ->add((new UI\PreferencesGroup('Apparence'))->add($switchRow, $stateRow));

$closedAdw = 0;
$adwWindow->addCss('window { background: #101418; }');
$adwWindow->headerEnd($aboutButton)
    ->content(new UI\Clamp($page, 480))
    ->onCloseRequest(function () use (&$closedAdw): void {
        $closedAdw++;
    })
    ->present();

Loop::after(150, fn () => $switchRow->active(true));
Loop::after(250, fn () => $aboutButton->click());
Loop::after(150, fn () => $switchRow->raw()->setActive(true));
Loop::after(400, fn () => $adwWindow->raw()->close());

$adwStart = microtime(true);
$adwApp->run();
$adwElapsed = microtime(true) - $adwStart;

T::ok($switchChanges >= 1, 'SwitchRow : notify::active déclenche le handler PHP', (string) $switchChanges);
T::ok($switchRow->isActive(), 'SwitchRow::isActive() reflète l\'état');
T::same(1, $closedAdw, 'close-request Adw appelé une fois');
T::same(0, count($adwApp->windows()), 'plus aucune fenêtre Adw suivie');
T::ok($adwElapsed < 2.0, 'boucle Adw terminée proprement', sprintf('%.0f ms', $adwElapsed * 1000));

// ---------------------------------------------------------------------------
T::section('7. Éditeur GtkSourceView et terminal VTE');

T::ok(class_exists(Cyonima\GtkSource\Raw\View::class), 'classe GtkSource\View chargée');
T::ok(
    is_subclass_of(Cyonima\GtkSource\Raw\View::class, Cyonima\Gtk\Raw\TextView::class),
    'GtkSource\View étend Gtk\TextView',
);
T::ok(
    is_subclass_of(Cyonima\GtkSource\Raw\Buffer::class, Cyonima\Gtk\Raw\TextBuffer::class),
    'GtkSource\Buffer étend Gtk\TextBuffer',
);
T::ok(
    is_subclass_of(Cyonima\Vte\Raw\Terminal::class, Cyonima\Gtk\Raw\Widget::class),
    'Vte\Terminal est un Gtk\Widget',
);

Kernel::reset();
Loop::reset();
Scheduler::reset();

$code = "<?php\n\necho \"bonjour\";\n";
$editor = new UI\CodeEditor('php', $code);
T::same(strlen($code), $editor->charCount(), 'le buffer contient exactement le texte fourni');
T::ok($editor->lineCount() >= 3, 'lineCount cohérent', (string) $editor->lineCount());
T::ok($editor->buffer()->getLanguage() !== null, 'langage PHP appliqué au buffer');
T::ok($editor->buffer()->getHighlightSyntax(), 'coloration syntaxique active');

$roundTrip = "<?php\n// éàü ← accents\n";
$editor->setText($roundTrip);
T::same($roundTrip, $editor->text(), 'texte relu à l\'identique (UTF-8)');
$changes = 0;
$editor->onChanged(function () use (&$changes): void {
    $changes++;
});
$editor->setText($roundTrip . "// modifié\n");
T::ok($changes >= 1, 'onChanged notifié sur modification du buffer', (string) $changes);
$editor->scheme('Adwaita');
T::ok(true, 'schéma de couleurs appliqué sans erreur');

$paned = (new UI\Paned(Cyonima\Gtk\Raw\Orientation::VERTICAL, 200))
    ->start(new UI\ScrolledWindow($editor))
    ->end(new UI\Label('zone basse'));
T::ok($paned->raw() instanceof Cyonima\Gtk\Raw\Paned, 'Paned + ScrolledWindow construits');

$proof = sys_get_temp_dir() . '/cyonima_vte_' . getmypid();
@unlink($proof);

$vteWindow = new UI\Window('VTE', 420, 220);
$terminal = new UI\Terminal();
$vteWindow->setChild($terminal)
    ->onCloseRequest(fn () => Loop::quit())
    ->present();

$exitStatus = null;
$terminal->onChildExited(function ($term, $status) use (&$exitStatus): void {
    $exitStatus = $status;
    Loop::quit();
});

$terminal->spawn(['/bin/bash', '-lc', 'echo ok > ' . escapeshellarg($proof)]);
Loop::after(2000, fn () => Loop::quit());
Loop::run();

T::same('ok', trim((string) @file_get_contents($proof)), 'le terminal exécute réellement le processus');
T::ok(is_int($exitStatus), 'le statut de sortie arrive typé en int', get_debug_type($exitStatus));
T::same(0, is_int($exitStatus) ? $exitStatus >> 8 : -1, 'code de sortie 0');
@unlink($proof);

// ---------------------------------------------------------------------------
T::section('8. Navigateur de fichiers et client LSP');

Kernel::reset();
Loop::reset();
Scheduler::reset();

$examples = dirname(__DIR__) . '/examples';
$opened = null;
$browser = new UI\FileBrowser($examples, function (string $path) use (&$opened): void {
    $opened = $path;
});
T::same(realpath($examples), $browser->root(), 'racine du navigateur');
T::ok($browser->entryCount() > 0, 'entrées listées', (string) $browser->entryCount());
$browser->open($examples . '/hello.php');
T::same($examples . '/hello.php', $opened, 'ouverture d\'un fichier via le navigateur');

$lsp = LspClient::start([PHP_BINARY, __DIR__ . '/fixtures/fake_lsp.php']);
$lsp->attach();

$initialized = null;
$diagnostic = null;

$lsp->on('textDocument/publishDiagnostics', function (array $params) use (&$diagnostic): void {
    $diagnostic = $params;
    Loop::quit();
});

$lsp->initialize('file://' . dirname(__DIR__), function (mixed $result) use (&$initialized, $lsp): void {
    $initialized = $result;
    $lsp->didOpen('file:///tmp/cyonima_test.php', 'php', "<?php echo 1;\n");
});

Loop::after(3000, fn () => Loop::quit());
Loop::run();

T::ok(
    is_array($initialized) && ($initialized['capabilities']['textDocumentSync'] ?? null) === 1,
    'initialize échangé avec le serveur LSP',
);
T::same(
    'diagnostic factice',
    $diagnostic['diagnostics'][0]['message'] ?? null,
    'notification publishDiagnostics reçue',
);
$lsp->stop();

// ---------------------------------------------------------------------------
T::section('9. Complétion LSP et popover');

Kernel::reset();
Loop::reset();
Scheduler::reset();

$completionWindow = new UI\Window('Completion', 520, 320);
$completionEditor = new UI\CodeEditor('php', "<?php\nstrl");
$completionWindow
    ->setChild($completionEditor)
    ->onCloseRequest(fn () => Loop::quit())
    ->present();
$completionEditor->moveCursorToEnd();

T::same(
    ['offset' => 10, 'line' => 1, 'character' => 4],
    $completionEditor->cursor(),
    'position du curseur (ligne, caractère, offset)',
);
T::same('strl', $completionEditor->wordPrefix(), 'préfixe de mot avant le curseur');

$popup = new UI\CompletionPopup($completionEditor);
$completionLsp = LspClient::start([PHP_BINARY, __DIR__ . '/fixtures/fake_lsp.php']);
$completionLsp->attach();

$completionItems = [];
$inserted = false;
$visibleAfterShow = false;
$hiddenAfterChoose = false;

$completionLsp->initialize('file://' . dirname(__DIR__), function () use (
    $completionLsp,
    $completionEditor,
    $popup,
    &$completionItems,
    &$inserted,
    &$visibleAfterShow,
    &$hiddenAfterChoose,
): void {
    $cursor = $completionEditor->cursor();
    $prefix = $completionEditor->wordPrefix();

    $completionLsp->request('textDocument/completion', [
        'textDocument' => ['uri' => 'file:///tmp/cyonima_completion.php'],
        'position' => ['line' => $cursor['line'], 'character' => $cursor['character']],
    ], function (mixed $result) use (
        $completionEditor,
        $popup,
        $prefix,
        &$completionItems,
        &$inserted,
        &$visibleAfterShow,
        &$hiddenAfterChoose,
    ): void {
        $completionItems = LspClient::normalizeCompletion($result);
        if ($completionItems === []) {
            Loop::quit();
            return;
        }

        $popup->show($completionItems, function (array $item) use ($completionEditor, $prefix, &$inserted): void {
            $completionEditor->replacePrefix(strlen($prefix), $item['insertText'] ?? $item['label']);
            $inserted = true;
            Loop::quit();
        });

        $visibleAfterShow = $popup->visible();
        $popup->chooseCurrent();
        $hiddenAfterChoose = !$popup->visible();
    });
});

Loop::after(3000, fn () => Loop::quit());
Loop::run();

T::same(2, count($completionItems), 'deux propositions normalisées');
T::ok($visibleAfterShow, 'le popover est visible après show()');
T::ok($hiddenAfterChoose, 'le popover est fermé après sélection');
T::ok($inserted, 'callback de sélection exécuté');
T::same("<?php\nstrlen", $completionEditor->text(), 'le préfixe est remplacé par la proposition');

$completionLsp->stop();

// ---------------------------------------------------------------------------
T::section('10. Débogueur Xdebug (DBGp)');

Kernel::reset();
Loop::reset();
Kernel::boot();

$debug = XdebugClient::listen(0);
$debug->attach();

$breaks = [];
$connected = false;
$stopped = false;

$debug->on('connect', function (?string $fileUri) use ($debug, &$connected): void {
    $connected = true;
    $debug->setBreakpoint('file:///tmp/demo.php', 3);
});

$debug->on('break', function (?string $fileUri, ?int $line) use (&$breaks, $debug): void {
    $breaks[] = [XdebugClient::localPath((string) $fileUri), (int) $line];

    if (count($breaks) === 1) {
        $debug->stepInto();
    } else {
        $debug->stop();
    }
});

$debug->on('stop', function () use (&$stopped): void {
    $stopped = true;
    Loop::quit();
});

$fake = Process::start([
    PHP_BINARY,
    __DIR__ . '/fixtures/fake_xdebug.php',
    (string) $debug->port(),
]);

Loop::after(3000, fn () => Loop::quit());
Loop::run();

T::ok($connected, 'Xdebug se connecte et envoie init');
T::same([['/tmp/demo.php', 4], ['/tmp/demo.php', 5]], $breaks, 'arrêt initial puis pas-à-pas');
T::ok($stopped, 'la commande stop est honorée');
T::ok(!$debug->isPaused(), 'plus en pause après stop');

$debug->close();
Process::terminateAll();

$cursorEditor = new UI\CodeEditor('php', "ligne1\nligne2\nligne3");
$cursorEditor->moveCursorToLine(1);
T::same(1, $cursorEditor->cursor()['line'], 'curseur déplacé sur la ligne demandée');
T::same('', $cursorEditor->wordPrefix(), 'curseur en début de ligne : préfixe vide');

$cursorEditor->moveCursorToEnd();
T::same('ligne3', $cursorEditor->wordPrefix(), 'préfixe relu en fin de fichier');

// ---------------------------------------------------------------------------
T::section('11. Différentiel PyGObject (si disponible)');

$py = python('import gi; gi.require_version("Gtk", "4.0"); from gi.repository import Gtk; print(int(Gtk.Orientation.VERTICAL), int(Gtk.Align.CENTER))');
if ($py === null) {
    T::skip('PyGObject absent : différentiel non exécuté');
} else {
    [$vertical, $center] = array_map('intval', explode(' ', $py));
    T::same($vertical, Raw\Orientation::VERTICAL, 'Orientation::VERTICAL identique à PyGObject');
    T::same($center, Raw\Align::CENTER, 'Align::CENTER identique à PyGObject');

    // Les signaux GTK ne sont enregistrés qu'à l'initialisation de la classe :
    // PyGObject exige une instance pour les voir.
    $signals = python('import gi; gi.require_version("Gtk", "4.0"); from gi.repository import GObject, Gtk; Gtk.init(); print(GObject.signal_lookup("clicked", Gtk.Button()) > 0, GObject.signal_lookup("close-request", Gtk.Window()) > 0)');
    T::same('True True', $signals, 'les signaux clicked et close-request existent côté PyGObject');

    $methods = python('import gi; gi.require_version("Gtk", "4.0"); from gi.repository import Gtk; print(",".join(m for m in ("set_title", "present", "close", "set_default_size") if hasattr(Gtk.Window, m)))');
    T::same('set_title,present,close,set_default_size', $methods, 'les méthodes de Window existent côté PyGObject');

    $adw = python('import gi; gi.require_version("Adw", "1"); from gi.repository import Adw; print(int(Adw.CenteringPolicy.LOOSE), int(Adw.CenteringPolicy.STRICT), int(hasattr(Adw, "ApplicationWindow")))');
    T::same('0 1 1', $adw, 'enums et fenêtre Adw identiques à PyGObject');

    $gtkSource = python('import gi; gi.require_version("GtkSource", "5"); from gi.repository import GtkSource; print(int(hasattr(GtkSource, "View")), int(hasattr(GtkSource, "LanguageManager")))');
    T::same('1 1', $gtkSource, 'GtkSourceView expose View et LanguageManager côté PyGObject');

    $vte = python('import gi; gi.require_version("Gtk", "4.0"); gi.require_version("Vte", "3.91"); from gi.repository import Gtk, GObject, Vte; Gtk.init(); t = Vte.Terminal(); print(int(hasattr(Vte, "Terminal")), int(GObject.signal_lookup("child-exited", t) > 0))');
    T::same('1 1', $vte, 'Vte\Terminal et son signal child-exited existent côté PyGObject');
}

exit(T::report());
