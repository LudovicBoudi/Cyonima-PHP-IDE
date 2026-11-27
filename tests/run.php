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

require_once dirname(__DIR__) . '/autoload.php';

use Cyonima\Generator\Generator;
use Cyonima\Generator\Spec;
use Cyonima\Gtk\Raw;
use Cyonima\Runtime\AdwApplication;
use Cyonima\Runtime\Application;
use Cyonima\Runtime\Async;
use Cyonima\Runtime\Errors;
use Cyonima\Runtime\GitRepository;
use Cyonima\Runtime\Kernel;
use Cyonima\Runtime\Loop;
use Cyonima\Runtime\Markdown;
use Cyonima\Runtime\LspClient;
use Cyonima\Runtime\OutputLog;
use Cyonima\Runtime\PhpIntellisense;
use Cyonima\Runtime\Config;
use Cyonima\Runtime\Diagnostics;
use Cyonima\Runtime\FileWatcher;
use Cyonima\Runtime\GtkScroll;
use Cyonima\Runtime\GtkText;
use Cyonima\Compiler\FormCompiler;
use Cyonima\Compiler\Lexer\Lexer;
use Cyonima\Compiler\Lexer\TokenType;
use Cyonima\Compiler\Parser\Parser;
use Cyonima\Compiler\PhpCompiler;
use Cyonima\Compiler\Template\TemplateCompiler;
use Cyonima\Runtime\FormDocument;
use Cyonima\Runtime\ProjectFactory;
use Cyonima\Runtime\ProjectSearch;
use Cyonima\Runtime\QuickOpenMatcher;
use Cyonima\Runtime\RecentProjects;
use Cyonima\Runtime\SymbolIndex;
use Cyonima\Runtime\TextSearch;
use Cyonima\Runtime\XdebugClient;
use Cyonima\Runtime\Process;
use Cyonima\Runtime\Scheduler;
use Cyonima\Runtime\ScrollSync;
use Cyonima\Runtime\SearchHistory;
use Cyonima\Runtime\WorkspaceWatcher;
use Cyonima\UI;
use Cyonima\UI\CodeEditor;
use Cyonima\UI\CommandPalette;
use Cyonima\UI\DiffPanel;
use Cyonima\UI\FindBar;
use Cyonima\UI\GitPanel;
use Cyonima\UI\MarkdownPreview;
use Cyonima\UI\OutputPanel;
use Cyonima\UI\ProblemsPanel;
use Cyonima\UI\QuickOpen;
use Cyonima\UI\SearchPanel;
use Cyonima\UI\SymbolSearch;

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

$themeWindow = new UI\Window('Theme', 400, 300);
$themeEditor = new UI\CodeEditor('php', "<?php\necho 1;\n");
UI\Theme::apply($themeWindow, $themeEditor);
T::ok($themeEditor->buffer()->getStyleScheme() !== null, 'thème VS : schéma de couleurs appliqué');

$iconClicked = false;
$iconButton = (new UI\IconButton('folder-symbolic'))->onClicked(
    function () use (&$iconClicked): void {
        $iconClicked = true;
    },
);
$iconButton->click();
T::ok($iconClicked, 'IconButton déclenche son handler');

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

$spawnPid = null;
$spawnError = null;
$terminal->spawn(
    ['/bin/sh', '-c', 'echo ok > ' . escapeshellarg($proof)],
    null,
    function (int $pid, ?string $error) use (&$spawnPid, &$spawnError): void {
        $spawnPid = $pid;
        $spawnError = $error;
    },
);
// Les runners CI peuvent être lents au premier démarrage du PTY.
Loop::after(10000, fn () => Loop::quit());
Loop::run();

$proofContent = trim((string) @file_get_contents($proof));

if ($spawnPid === null && $spawnError !== null) {
    T::skip('terminal PTY indisponible dans cet environnement : ' . $spawnError);
} else {
    T::ok(
        $proofContent === 'ok',
        'le terminal exécute réellement le processus',
        'sortie=' . var_export($proofContent, true)
            . ', pid=' . var_export($spawnPid, true)
            . ', erreur=' . var_export($spawnError, true)
            . ', statut=' . var_export($exitStatus, true),
    );
    T::ok(is_int($exitStatus), 'le statut de sortie arrive typé en int', get_debug_type($exitStatus));
    T::same(0, is_int($exitStatus) ? $exitStatus >> 8 : -1, 'code de sortie 0');
}

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

$openedByClick = null;
$clickBrowser = new UI\FileBrowser($examples, function (string $path) use (&$openedByClick): void {
    $openedByClick = $path;
});
$firstFile = $clickBrowser->firstFileIndex();
$clickBrowser->activateRow($firstFile ?? 0);
T::same($examples . '/adw.php', $openedByClick, 'clic réel : row-activated ouvre le premier fichier');

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
$caretRect = null;
$filteredCount = 0;
$visibleAfterPresent = false;

$completionLsp->initialize('file://' . dirname(__DIR__), function () use (
    $completionLsp,
    $completionEditor,
    $popup,
    &$completionItems,
    &$inserted,
    &$visibleAfterShow,
    &$hiddenAfterChoose,
    &$caretRect,
    &$filteredCount,
    &$visibleAfterPresent,
): void {
    $caretRect = $completionEditor->cursorRect();
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
        &$filteredCount,
        &$visibleAfterPresent,
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
        }, null, 'str');

        $visibleAfterShow = $popup->visible();

        $popup->filterByPrefix('strl');
        $filteredCount = $popup->count();

        $popup->present();
        $visibleAfterPresent = $popup->visible();
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
T::ok(($caretRect['height'] ?? 0) > 0, 'rectangle du curseur disponible pour IntelliSense');
T::same(1, $filteredCount, 'filtrage local : une seule proposition restante');
T::ok($visibleAfterPresent, 'liste réaffichée après filtrage');

$sshSuggestions = PhpIntellisense::suggest('ssh2_');
T::ok(count($sshSuggestions) >= 4, 'IntelliSense : fonctions ssh2 disponibles');

$builtin = PhpIntellisense::suggest('str');
T::ok(count($builtin) >= 5, 'IntelliSense intégré : plusieurs propositions', (string) count($builtin));
T::ok(in_array('strlen', array_column($builtin, 'label'), true), 'strlen proposé sans LSP');
T::same([], PhpIntellisense::suggest('zzzzz'), 'aucune proposition improbable');

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
$stack = [];
$variables = [];
$expanded = [];

$debug->on('connect', function (?string $fileUri) use ($debug, &$connected): void {
    $connected = true;
    $debug->setBreakpoint('file:///tmp/demo.php', 3);
});

$debug->on('break', function (?string $fileUri, ?int $line) use (
    &$breaks,
    &$stack,
    &$variables,
    &$expanded,
    $debug,
): void {
    $breaks[] = [XdebugClient::localPath((string) $fileUri), (int) $line];

    if (count($breaks) === 1) {
        $debug->stackGet(function (array $frames) use (&$stack): void {
            $stack = $frames;
        });
        $debug->contextGet(0, function (array $locals) use (&$variables): void {
            $variables = $locals;
        });
        $debug->propertyGet('$items', 0, function (array $children) use (&$expanded): void {
            $expanded = $children;
        });
        $debug->stepInto();
        return;
    }

    $debug->stop();
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

T::same(2, count($stack), 'pile : deux frames');
T::same('/tmp/demo.php', XdebugClient::localPath((string) ($stack[0]['fileUri'] ?? '')), 'frame 0 : fichier');
T::same(4, $stack[0]['line'] ?? null, 'frame 0 : ligne');
T::same('demo.php', $stack[0]['where'] ?? null, 'frame 0 : fonction');
T::same(3, count($variables), 'trois variables locales');
T::same('int', $variables[0]['type'] ?? null, 'type de $x');
T::same('42', $variables[0]['value'] ?? null, 'valeur de $x');
T::ok($variables[2]['children'] ?? false, '$items est développable');
T::same(['un', 'deux'], array_column($expanded, 'value'), 'enfants de $items');

$panel = new UI\DebugPanel();
$panel->showStack([
    ['level' => 0, 'type' => 'file', 'fileUri' => 'file:///tmp/demo.php', 'line' => 4, 'where' => 'demo.php'],
    ['level' => 1, 'type' => 'file', 'fileUri' => 'file:///tmp/demo.php', 'line' => 10, 'where' => '{main}'],
]);
$panel->showVariables([
    ['name' => '$x', 'fullname' => '$x', 'type' => 'int', 'value' => '42', 'children' => false, 'numChildren' => 0],
    ['name' => '$items', 'fullname' => '$items', 'type' => 'array', 'value' => '', 'children' => true, 'numChildren' => 2],
]);
T::same(2, $panel->frameCount(), 'panneau : deux frames');
T::same(2, $panel->variableCount(), 'panneau : deux variables');
$panel->clear();
T::same(0, $panel->frameCount() + $panel->variableCount(), 'panneau vidé');

$debug->close();
Process::terminateAll();

$cursorEditor = new UI\CodeEditor('php', "ligne1\nligne2\nligne3");
$cursorEditor->moveCursorToLine(1);
T::same(1, $cursorEditor->cursor()['line'], 'curseur déplacé sur la ligne demandée');
T::same('', $cursorEditor->wordPrefix(), 'curseur en début de ligne : préfixe vide');

$cursorEditor->moveCursorToEnd();
T::same('ligne3', $cursorEditor->wordPrefix(), 'préfixe relu en fin de fichier');

$historyEditor = new UI\CodeEditor('php', 'abc');
T::ok(!$historyEditor->canUndo(), 'éditeur : historique vidé au chargement');
$historyEditor->moveCursorToEnd();
$historyEditor->replacePrefix(0, 'd');
T::ok($historyEditor->canUndo(), 'éditeur : modification annulable');
$historyEditor->undo();
T::same('abc', $historyEditor->text(), 'éditeur : annulation');
$historyEditor->redo();
T::same('abcd', $historyEditor->text(), 'éditeur : rétablissement');
$historyEditor->selectAll();
T::ok($historyEditor->hasSelection(), 'éditeur : tout sélectionner');

$menuBar = new UI\MenuBar();
$menuBar->addMenu('Test', [
    ['label' => 'Action', 'shortcut' => 'Ctrl+T', 'action' => static fn (): null => null],
    ['separator' => true],
]);
T::ok($menuBar instanceof UI\MenuBar, 'barre de menus : construction');

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


// ---------------------------------------------------------------------------
T::section('12. Démarrage : licences de projet, projets récents, assets');

$removeTree = function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        if (is_file($path)) {
            @unlink($path);
        }
        return;
    }

    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        $child = $path . '/' . $entry;
        if (is_dir($child) && !is_link($child)) {
            $removeTree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
};

$base = sys_get_temp_dir() . '/cyonima_start_' . getmypid();
$removeTree($base);
mkdir($base, 0775, true);

putenv('CYONIMA_CONFIG_DIR=' . $base . '/config');

T::same($base . '/config', Config::dir(), 'config : répertoire surchargé');
T::same($base . '/config/recents.json', Config::file('recents.json'), 'config : fichier résolu');

$recent = new RecentProjects($base . '/recent.json');
$first = $base . '/recent-un';
$second = $base . '/recent-deux';
mkdir($first);
mkdir($second);

$recent->add($first, 'Premier', 'gtk');
$recent->add($first, 'Premier bis', 'console');
$recent->add($second, 'Second', 'library');
$recentEntries = $recent->all();
T::same(2, count($recentEntries), 'projets récents : dédoublonnage');
T::same('Second', $recentEntries[0]['name'] ?? null, 'projets récents : plus récent en tête');
$recent->remove($first);
T::same(1, count($recent->all()), 'projets récents : suppression');
$recent->clear();
T::same([], $recent->all(), 'projets récents : vidage');

T::same(3, count(ProjectFactory::templates()), 'modèles : GTK, console et bibliothèque');
T::same(4, count(ProjectFactory::licenses()), 'licences de projet : quatre choix');
T::same('cafe-creatif', ProjectFactory::slug('Café Créatif'), 'slug : accents translittérés');
T::same('com.example.app123projet', ProjectFactory::suggestAppId('123 Projet'), 'identifiant proposé toujours valide');

$invalid = ProjectFactory::create('', $base, 'gtk');
T::ok(!$invalid['ok'], 'projet : nom vide refusé');

$extraAsset = $base . '/logo.png';
file_put_contents($extraAsset, "\x89PNG\r\n\x1a\n");

$project = ProjectFactory::create(
    'Mon Application',
    $base . '/projets',
    ProjectFactory::TEMPLATE_GTK,
    'MIT',
    'com.exemple.monapplication',
    [$extraAsset],
    '/home/ludo/Documents/Dev/Cyonima-PHP-IDE',
);

T::ok($project['ok'], 'projet GTK : création', $project['message']);
$projectPath = (string) $project['path'];
T::ok(is_dir($projectPath), 'projet GTK : dossier créé');
T::ok(is_file($projectPath . '/.cyonima/project.json'), 'projet GTK : manifeste');
T::ok(is_file($projectPath . '/src/main.php'), 'projet GTK : point d\'entrée');
T::ok(is_file($projectPath . '/autoload.php'), 'projet GTK : autoload');
T::ok(is_file($projectPath . '/LICENSE'), 'projet GTK : licence');
T::ok(is_file($projectPath . '/README.md'), 'projet GTK : README');
T::ok(is_file($projectPath . '/assets/icon.svg'), 'projet GTK : icône');
T::ok(is_file($projectPath . '/assets/theme.css'), 'projet GTK : thème');
T::ok(is_file($projectPath . '/assets/logo.png'), 'projet GTK : asset importé');

$manifest = ProjectFactory::manifest($projectPath) ?? [];
T::same('Mon Application', $manifest['name'] ?? null, 'manifeste : nom');
T::same('MIT', $manifest['license']['spdx'] ?? null, 'manifeste : licence');
T::same('gtk', $manifest['template'] ?? null, 'manifeste : modèle');
T::same(4, count($manifest['assets'] ?? []), 'manifeste : assets référencés');
T::same(4, count(ProjectFactory::listAssets($projectPath)), 'assets : liste sur disque');
T::same('video', ProjectFactory::assetKind('/tmp/intro.mp4'), 'assets : type vidéo reconnu');

$another = $base . '/police.woff2';
file_put_contents($another, 'font');
$import = ProjectFactory::importAsset($projectPath, $another);
T::ok($import['ok'], 'assets : import');
T::same(5, count(ProjectFactory::listAssets($projectPath)), 'assets : import compté');
T::ok(str_contains((string) file_get_contents($projectPath . '/.cyonima/project.json'), 'police.woff2'), 'assets : manifeste rafraîchi');
$removed = ProjectFactory::removeAsset($projectPath, 'police.woff2');
T::ok($removed['ok'], 'assets : suppression');
T::same(4, count(ProjectFactory::listAssets($projectPath)), 'assets : suppression comptée');

file_put_contents($projectPath . '/logo', 'asset local');
$relative = ProjectFactory::importAsset($projectPath, 'logo');
T::ok($relative['ok'], 'assets : import relatif au projet');
T::same('assets/logo', $relative['path'] ?? null, 'assets : chemin relatif conservé');
T::same(5, count(ProjectFactory::listAssets($projectPath)), 'assets : import relatif compté');
ProjectFactory::removeAsset($projectPath, 'logo');

$assetPanel = new UI\AssetPanel($projectPath, static fn (string $path): null => null);
T::same(4, $assetPanel->count(), 'panneau assets : liste du projet');

$duplicate = ProjectFactory::create('Mon Application', $base . '/projets', 'gtk');
T::ok(!$duplicate['ok'], 'projet : dossier existant refusé');
$licenseUpdate = ProjectFactory::updateLicense($projectPath, 'GPL-3.0');
T::ok($licenseUpdate['ok'], 'licence projet : mise à jour');
$updatedManifest = ProjectFactory::manifest($projectPath) ?? [];
T::same('GPL-3.0', $updatedManifest['license']['spdx'] ?? null, 'licence projet : manifeste mis à jour');
T::ok(
    str_contains((string) file_get_contents($projectPath . '/LICENSE'), 'GNU General Public License'),
    'licence projet : texte LICENSE réécrit',
);
T::same(4, count(ProjectFactory::listAssets($projectPath)), 'licence projet : assets préservés');
$invalidLicense = ProjectFactory::updateLicense($projectPath, 'WTFPL');
T::ok(!$invalidLicense['ok'], 'licence projet : licence inconnue refusée');
T::ok(!ProjectFactory::updateLicense($base, 'MIT')['ok'], 'licence projet : manifeste obligatoire');

$propertiesPanel = new UI\ProjectPropertiesPanel($projectPath);
T::same('GPL-3.0', $propertiesPanel->selectedLicense(), 'propriétés : licence courante sélectionnée');
T::same('Mon Application', $propertiesPanel->projectName(), 'propriétés : nom du projet');


$console = ProjectFactory::create('Outil CLI', $base . '/projets', ProjectFactory::TEMPLATE_CONSOLE, 'Apache-2.0');
T::ok($console['ok'], 'projet console : création');
T::ok(str_contains((string) file_get_contents((string) $console['path'] . '/src/main.php'), 'PHP_VERSION'), 'projet console : script');

$library = ProjectFactory::create('Ma Bibliothèque', $base . '/projets', ProjectFactory::TEMPLATE_LIBRARY, 'GPL-3.0');
T::ok($library['ok'], 'projet bibliothèque : création');
T::ok(is_file((string) $library['path'] . '/src/Greeter.php'), 'projet bibliothèque : classe App\\Greeter');
T::ok(is_file((string) $library['path'] . '/tests/test.php'), 'projet bibliothèque : test');

$consoleOutput = shell_exec(PHP_BINARY . ' ' . escapeshellarg((string) $console['path'] . '/src/main.php') . ' 2>&1');
T::ok(str_contains((string) $consoleOutput, 'Outil CLI'), 'projet console : exécution');

$libraryOutput = shell_exec(PHP_BINARY . ' ' . escapeshellarg((string) $library['path'] . '/tests/test.php') . ' 2>&1');
T::ok(str_contains((string) $libraryOutput, 'Bonjour, Cyonima'), 'projet bibliothèque : test exécuté');

exec(PHP_BINARY . ' -l ' . escapeshellarg($projectPath . '/src/main.php') . ' 2>&1', $lintLines, $lintStatus);
T::same(0, $lintStatus, 'projet GTK : syntaxe du point d\'entrée');

$entry = new UI\Entry('Nom du projet', 'Projet');
T::same('Projet', $entry->text(), 'Entry : texte initial');
$entry->setText('Cyonima');
T::same('Cyonima', $entry->text(), 'Entry : setText');

$startWindow = new Cyonima\App\StartWindow(dirname(__DIR__));
T::ok($startWindow instanceof Cyonima\App\StartWindow, 'fenêtre de démarrage : construction');

$removeTree($base);


// ---------------------------------------------------------------------------
T::section('13. Concepteur visuel et formulaires');

T::same(7, count(FormDocument::types()), 'concepteur : sept types de contrôles');

$form = new FormDocument('Main Form', 720, 480);
$labelItem = $form->add('label', 20, 30, 'Bonjour');
$buttonItem = $form->add('button', 20, 90, 'Valider');
T::same('Bonjour', $labelItem['text'], 'formulaire : texte initial');
T::same(2, count($form->widgets()), 'formulaire : deux contrôles');

$updated = $form->update($labelItem['id'], ['x' => 40, 'y' => 50, 'text' => 'Titre']);
T::same(40, $updated['x'] ?? null, 'formulaire : déplacement X');
T::same('Titre', $updated['text'] ?? null, 'formulaire : texte modifié');

$roundTrip = FormDocument::fromArray($form->toArray());
T::same(2, count($roundTrip->widgets()), 'formulaire : sérialisation aller-retour');
T::same('MainForm', $form->className(), 'formulaire : nom de classe');

$php = $form->toPhp();
T::ok(str_contains($php, "new Label('Titre')"), 'formulaire : code PHP du label');
T::ok(str_contains($php, "new Button('Valider')"), 'formulaire : code PHP du bouton');
T::ok(str_contains($php, 'sizeRequest(180, 28)'), 'formulaire : taille générée');

$mediaForm = new FormDocument('Media Form');
$mediaForm->add('image', 10, 10, 'assets/icon.svg');
$mediaForm->add('video', 10, 150, 'assets/demo.mp4');
$mediaForm->add('separator', 10, 350);
$mediaBase = $mediaForm->toBasePhp();
T::ok(str_contains($mediaBase, 'new Image('), 'formulaire : contrôle image');
T::ok(str_contains($mediaBase, 'new Video('), 'formulaire : contrôle vidéo');
T::ok(str_contains($mediaBase, 'new Separator()'), 'formulaire : séparateur');

$formRoot = sys_get_temp_dir() . '/cyonima_form_' . getmypid();
$removeTree($formRoot);
mkdir($formRoot, 0775, true);

$namedForm = new FormDocument('Named Form');
$namedButton = $namedForm->add('button', 30, 40, 'Cliquer');
$namedForm->update($namedButton['id'], [
    'name' => 'btnValider',
    'events' => ['click', 'double-click'],
]);
$namedBase = $namedForm->toBasePhp();
$namedUser = $namedForm->toUserPhp();
T::ok(str_contains($namedBase, 'public Button $btnValider;'), 'formulaire : contrôle nommé');
T::ok(str_contains($namedBase, '->onClicked'), 'formulaire : événement click');
T::ok(str_contains($namedBase, '->onDoubleClick'), 'formulaire : événement double-click');
T::ok(str_contains($namedBase, 'onBtnValiderClick'), 'formulaire : méthode de click nommée');
T::ok(str_contains($namedUser, 'extends NamedFormBase'), 'formulaire : sous-classe utilisateur');

$iconForm = new FormDocument('Icon Form');
$iconButton = $iconForm->add('button', 20, 30, 'Valider');
$iconForm->update($iconButton['id'], ['icon' => 'assets/icon.svg']);
$iconBase = $iconForm->toBasePhp();
T::ok(str_contains($iconBase, 'new Image(dirname(__DIR__, 2)'), 'formulaire : image dans un bouton');
T::ok(str_contains($iconBase, 'new Box(Orientation::HORIZONTAL, 6)'), 'formulaire : contenu image + texte');
$iconRoundTrip = FormDocument::fromArray($iconForm->toArray());
T::same('assets/icon.svg', $iconRoundTrip->widgets()[0]['icon'] ?? null, 'formulaire : icône sérialisée');

$namedRoot = $formRoot . '/named';
mkdir($namedRoot, 0775, true);
$namedSave = $namedForm->save($namedRoot);
T::ok($namedSave['ok'], 'formulaire nommé : sauvegarde');
T::ok(is_file((string) $namedSave['basePath']), 'formulaire nommé : classe de base générée');
T::ok(is_file((string) $namedSave['phpPath']), 'formulaire nommé : sous-classe générée');

file_put_contents((string) $namedSave['phpPath'], (string) file_get_contents((string) $namedSave['phpPath']) . "\n// personnalisation\n");
$namedForm->save($namedRoot);
T::ok(str_contains((string) file_get_contents((string) $namedSave['phpPath']), 'personnalisation'), 'formulaire nommé : sous-classe préservée');

$newEntry = $namedForm->add('entry', 50, 120, 'Email');
$namedForm->update($newEntry['id'], ['name' => 'entryEmail', 'events' => ['changed']]);
$namedForm->save($namedRoot);

$mergedUser = (string) file_get_contents((string) $namedSave['phpPath']);
T::ok(str_contains($mergedUser, 'onEntryEmailChanged'), 'formulaire nommé : méthode ajoutée à la sous-classe');
T::ok(str_contains($mergedUser, 'personnalisation'), 'formulaire nommé : action existante conservée');


$saved = $form->save($formRoot);
T::ok($saved['ok'], 'formulaire : sauvegarde', $saved['message']);
T::ok(is_file((string) $saved['jsonPath']), 'formulaire : JSON écrit');
T::ok(is_file((string) $saved['phpPath']), 'formulaire : PHP écrit');

$mediaForm->save($formRoot);
T::same(2, count(FormDocument::listForms($formRoot)), 'formulaires : catalogue multiple');
T::same('form', FormDocument::uniqueFormName($formRoot, 'form'), 'formulaires : nom unique');

exec(PHP_BINARY . ' -l ' . escapeshellarg((string) $saved['phpPath']) . ' 2>&1', $formLintLines, $formLintStatus);
T::same(0, $formLintStatus, 'formulaire : syntaxe du PHP généré');

$loadedForm = FormDocument::load($formRoot, 'Main Form');
T::same(2, count($loadedForm?->widgets() ?? []), 'formulaire : rechargement');
T::ok($form->remove($buttonItem['id']), 'formulaire : suppression');
T::same(1, count($form->widgets()), 'formulaire : suppression effective');

$switchRow = new UI\SwitchRow('Option', '', false);
$switchRow->title('Nouvelle option')->subtitle('Sous-titre');
T::ok($switchRow instanceof UI\SwitchRow, 'SwitchRow : titre et sous-titre modifiables');

$removeTree($formRoot);


// ---------------------------------------------------------------------------
T::section('14. Compilateur natif — formulaire vers C');

$nativeRoot = sys_get_temp_dir() . '/cyonima_native_' . getmypid();
$removeTree($nativeRoot);
mkdir($nativeRoot, 0775, true);

$nativeProject = Cyonima\Runtime\ProjectFactory::create(
    'Native Compiler',
    $nativeRoot,
    'gtk',
    'MIT',
    'com.cyonima.nativecompiler',
);
$nativePath = (string) $nativeProject['path'];
$nativeForm = new Cyonima\Runtime\FormDocument('main-form');
$nativeLabel = $nativeForm->add('label', 20, 20, 'Bonjour');
$nativeButton = $nativeForm->add('button', 20, 70, 'Valider');
$nativeEntry = $nativeForm->add('entry', 20, 120, 'Votre nom');
$nativeSwitch = $nativeForm->add('switch', 20, 170, 'Option');
$nativeForm->update($nativeButton['id'], ['name' => 'btnValider', 'events' => ['click', 'double-click']]);
$nativeForm->update($nativeEntry['id'], ['name' => 'entryName', 'events' => ['changed', 'activate']]);
$nativeForm->update($nativeSwitch['id'], ['name' => 'switchOption', 'events' => ['changed']]);
$nativeForm->add('image', 20, 220, 'assets/icon.svg');
$nativeForm->add('video', 20, 280, 'assets/demo.mp4');
$nativeForm->add('separator', 20, 340);
$nativeForm->save($nativePath);

$nativeCompiler = new FormCompiler($nativePath);
$nativeC = $nativeCompiler->compile('main-form', null, 'c');
T::ok($nativeC['ok'], 'compilateur : génération C');
T::same($nativePath . '/dist/native/main-form.c', $nativeC['cPath'], 'compilateur : chemin C');
$cSource = (string) file_get_contents($nativeC['cPath']);
T::ok(str_contains($cSource, '#include <gtk/gtk.h>'), 'compilateur : include GTK');
T::ok(str_contains($cSource, 'gtk_fixed_put'), 'compilateur : placement des contrôles');
T::ok(str_contains($cSource, 'gtk_image_new_from_file'), 'compilateur : image');
T::ok(str_contains($cSource, 'gtk_video_new_for_filename'), 'compilateur : vidéo');
T::ok(str_contains($cSource, 'gtk_separator_new'), 'compilateur : séparateur');
T::ok(str_contains($cSource, 'on_w_btnValider_click'), 'compilateur : événement clic');
T::ok(str_contains($cSource, 'on_w_entryName_activate'), 'compilateur : événement activation');
T::ok(str_contains($cSource, 'notify::active'), 'compilateur : événement interrupteur');
T::ok(str_contains($cSource, 'com.cyonima.nativecompiler'), 'compilateur : identifiant d’application');

$cliOutput = [];
$cliStatus = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' '
    . escapeshellarg(dirname(__DIR__) . '/bin/cyonima-compile')
    . ' --project=' . escapeshellarg($nativePath)
    . ' --form=main-form --emit=c 2>&1',
    $cliOutput,
    $cliStatus,
);
T::same(0, $cliStatus, 'compilateur : CLI code de sortie');
T::ok(
    str_contains(implode("\n", $cliOutput), 'Code C généré'),
    'compilateur : CLI génère le C',
);

try {
    $nativeCompiler->compile('main-form', null, 'inconnu');
    T::ok(false, 'compilateur : sortie inconnue refusée');
} catch (Cyonima\Compiler\CompilerException) {
    T::ok(true, 'compilateur : sortie inconnue refusée');
}

try {
    $nativeCompiler->compile('formulaire-absent', null, 'c');
    T::ok(false, 'compilateur : formulaire absent refusé');
} catch (Cyonima\Compiler\CompilerException) {
    T::ok(true, 'compilateur : formulaire absent refusé');
}

$gtkPkg = trim((string) shell_exec('pkg-config --exists gtk4 2>/dev/null && echo yes || true')) === 'yes';
if (!$gtkPkg) {
    T::skip('GTK4 dev absent : compilation ELF non exécutée');
} else {
    $nativeElf = $nativeCompiler->compile('main-form', null, 'elf');
    T::ok($nativeElf['ok'], 'compilateur : binaire ELF');
    T::ok($nativeElf['binaryPath'] !== null && is_file($nativeElf['binaryPath']), 'compilateur : ELF présent');
    T::ok(is_executable((string) $nativeElf['binaryPath']), 'compilateur : ELF exécutable');
}

$removeTree($nativeRoot);

// ---------------------------------------------------------------------------
T::section('15. Compilateur PHP — lexer, parser, IR et backend C');

$phpRoot = sys_get_temp_dir() . '/cyonima_php_' . getmypid();
$removeTree($phpRoot);
mkdir($phpRoot . '/src', 0775, true);

$tokens = (new Lexer("<?php\n\$x = 1 + 2;\n"))->tokenize();
T::same(TokenType::Variable, $tokens[0]->type, 'lexer PHP : variable');
T::same('x', $tokens[0]->literal, 'lexer PHP : nom de variable');
T::same(TokenType::Assign, $tokens[1]->type, 'lexer PHP : affectation');
T::same(TokenType::Integer, $tokens[2]->type, 'lexer PHP : entier');
T::same(TokenType::Plus, $tokens[3]->type, 'lexer PHP : addition');
T::same(TokenType::Semicolon, $tokens[5]->type, 'lexer PHP : point-virgule');
T::same(TokenType::Eof, $tokens[6]->type, 'lexer PHP : fin de fichier');

$parserSource = <<<'PHP'
<?php
function double(int $n): int {
    return $n * 2;
}
echo double(3);
PHP;
$ast = (new Parser((new Lexer($parserSource))->tokenize()))->parse();
T::same(1, count($ast->functions), 'parser PHP : fonction');
T::same(1, count($ast->statements), 'parser PHP : instruction principale');
T::same('double', $ast->functions[0]->name, 'parser PHP : nom de fonction');
T::same(1, count($ast->functions[0]->params), 'parser PHP : paramètre');
T::same('int', $ast->functions[0]->returnType, 'parser PHP : type de retour');

$compilePhp = function (string $name, string $source, string $emit = 'c') use ($phpRoot): array {
    $path = $phpRoot . '/src/' . $name . '.php';
    file_put_contents($path, $source);

    return (new PhpCompiler($phpRoot))->compile($path, null, $emit);
};

$factorialSource = <<<'PHP'
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
PHP;

$factorial = $compilePhp('factorial', $factorialSource);
T::ok($factorial['ok'], 'compilateur PHP : génération C');
$phpCSource = (string) file_get_contents($factorial['cPath']);
T::ok(str_contains($phpCSource, '#include "cyonima_runtime.h"'), 'backend PHP : runtime natif');
T::ok(str_contains($phpCSource, 'int main(int argc, char **argv)'), 'backend PHP : main natif');
T::ok(str_contains($phpCSource, 'fn_factorial'), 'backend PHP : fonction C');
T::ok(str_contains($phpCSource, 'goto L'), 'IR PHP : contrôle de flux');
T::ok(str_contains($phpCSource, 'cy_echo_int'), 'backend PHP : echo');
T::ok(str_contains($phpCSource, 'cy_runtime_init();'), 'backend PHP : initialisation mémoire');

$hasGcc = trim((string) shell_exec('command -v gcc 2>/dev/null')) !== '';

if (!$hasGcc) {
    T::skip('gcc absent : exécution ELF du compilateur PHP non testée');
} else {
    $factorialElf = $compilePhp('factorial', $factorialSource, 'elf');
    T::ok(is_file((string) $factorialElf['binaryPath']), 'compilateur PHP : ELF');
    $output = shell_exec(escapeshellarg((string) $factorialElf['binaryPath']) . ' 2>&1');
    T::same("120\n", $output, 'compilateur PHP : exécution factorielle');

    $stringsElf = $compilePhp('strings', <<<'PHP'
<?php
function greet(string $name): string {
    return "Bonjour " . $name;
}

$value = 3;

if ($value >= 3) {
    echo greet("Cyonima"), "!\n";
} else {
    echo "petit\n";
}

$total = 7 / 2;
echo $total, "\n";
PHP, 'elf');
    T::same(
        "Bonjour Cyonima!\n3.5\n",
        shell_exec(escapeshellarg((string) $stringsElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : concaténation, if et flottants',
    );

    $builtinsElf = $compilePhp('builtins', <<<'PHP'
<?php
echo true, false, "\n";
echo strlen("abcd"), " ", intval("42"), " ", floatval("2.5"), " ", strval(7), "\n";
echo abs(-7), "\n";
PHP, 'elf');
    T::same(
        "1\n4 42 2.5 7\n7\n",
        shell_exec(escapeshellarg((string) $builtinsElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : fonctions natives',
    );

    $exampleElf = (new PhpCompiler($phpRoot))->compile(
        dirname(__DIR__) . '/examples/native-hello.php',
        null,
        'elf',
    );
    T::same(
        "Bonjour Cyonima !\n",
        shell_exec(escapeshellarg((string) $exampleElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : exemple du dépôt',
    );
}

$errors = [
    ['variable inconnue', '<?php echo $missing;', 'variable non définie'],
    ['type incompatible', '<?php $x = 1; $x = "a";', 'valeur string refusée'],
    ['fonction inconnue', '<?php foo();', 'fonction inconnue'],
    ['fonction imbriquée', '<?php function a(): void { function b(): void {} }', 'fonction imbriquée non supportée'],
    ['fonction dupliquée', '<?php function a(): void {} function a(): void {}', 'fonction déjà définie'],
    ['argument manquant', '<?php function f(int $a): int { return $a; } echo f();', 'attend 1 argument(s)'],
    ['argument invalide', '<?php function f(int $a): int { return $a; } echo f("x");', 'attend int, reçu string'],
    ['syntaxe incomplète', '<?php echo 1', 'attendu : ;'],
];

foreach ($errors as $index => [$label, $source, $needle]) {
    try {
        $compilePhp('error-' . $index, $source, 'c');
        T::ok(false, 'compilateur PHP : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'compilateur PHP : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

$cliOutput = [];
$cliStatus = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' '
    . escapeshellarg(dirname(__DIR__) . '/bin/cyonima-compile')
    . ' --project=' . escapeshellarg($phpRoot)
    . ' --php=' . escapeshellarg($phpRoot . '/src/factorial.php')
    . ' --emit=c 2>&1',
    $cliOutput,
    $cliStatus,
);
T::same(0, $cliStatus, 'compilateur PHP : CLI');
T::ok(
    str_contains(implode("\n", $cliOutput), 'Code C généré'),
    'compilateur PHP : CLI génère le C',
);

// ---------------------------------------------------------------------------
T::section('16. Compilateur PHP — tableaux, boucles et interpolation');

$forSource = <<<'PHP'
<?php
$sum = 0;
for ($i = 0; $i < 5; $i = $i + 1) {
    $sum = $sum + $i;
}
echo $sum, "\n";
PHP;

$arraysSource = <<<'PHP'
<?php
$items = [10, 20, 30];
$items[] = 40;
$items[1] = 25;
$total = 0;
foreach ($items as $value) {
    $total = $total + $value;
}
echo count($items), " ", $total, "\n";
PHP;

$arraysC = $compilePhp('arrays', $arraysSource, 'c');
$arraysCSource = (string) file_get_contents($arraysC['cPath']);
T::ok(str_contains($arraysCSource, 'cy_array_push_int'), 'backend PHP : construction de tableau');
T::ok(str_contains($arraysCSource, 'cy_array_get_int'), 'backend PHP : lecture indexée');
T::ok(str_contains($arraysCSource, 'cy_array_set_int'), 'backend PHP : écriture indexée');
T::ok(str_contains($arraysCSource, 'cy_array_count'), 'backend PHP : count');
T::ok(str_contains($arraysCSource, 'cy_roots_push'), 'runtime natif : racines GC');
T::ok(str_contains($arraysCSource, 'cy_gc_maybe_collect'), 'runtime natif : collecte aux boucles');

$interpSource = <<<'PHP'
<?php
$name = "Cyonima";
$version = 2;
echo "Bonjour $name, version $version\n";
echo "Salut {$name} !\n";
PHP;

$keysSource = <<<'PHP'
<?php
$items = [4, 5, 6];
$result = "";
foreach ($items as $index => $value) {
    $result = $result . $index . ":" . $value . " ";
}
echo $result, "\n";
PHP;

$copySource = <<<'PHP'
<?php
$a = [1, 2];
$b = $a;
$b[] = 3;
echo count($a), " ", count($b), "\n";
PHP;

$appendSource = <<<'PHP'
<?php
$values = [];
for ($i = 0; $i < 3; $i = $i + 1) {
    $values[] = $i * 2;
}
echo count($values), " ", $values[2], "\n";
PHP;

$boundsSource = <<<'PHP'
<?php
$a = [1];
echo $a[5];
PHP;

$stringLoopSource = <<<'PHP'
<?php
$text = "";
for ($i = 0; $i < 300; $i = $i + 1) {
    $text = $text . "x";
}
echo strlen($text), "\n";
PHP;

$arrayLoopSource = <<<'PHP'
<?php
$values = [];
for ($i = 0; $i < 200; $i = $i + 1) {
    $values[] = $i;
}
$sum = 0;
foreach ($values as $value) {
    $sum = $sum + $value;
}
echo count($values), " ", $sum, "\n";
PHP;

if (!$hasGcc) {
    T::skip('gcc absent : exécution des tableaux et boucles non testée');
} else {
    $forElf = $compilePhp('for', $forSource, 'elf');
    T::same(
        "10\n",
        shell_exec(escapeshellarg((string) $forElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : boucle for',
    );

    $arraysElf = $compilePhp('arrays', $arraysSource, 'elf');
    T::same(
        "4 105\n",
        shell_exec(escapeshellarg((string) $arraysElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : tableau, append et foreach',
    );

    $keysElf = $compilePhp('keys', $keysSource, 'elf');
    T::same(
        "0:4 1:5 2:6 \n",
        shell_exec(escapeshellarg((string) $keysElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : foreach clé => valeur',
    );

    $interpElf = $compilePhp('interp', $interpSource, 'elf');
    T::same(
        "Bonjour Cyonima, version 2\nSalut Cyonima !\n",
        shell_exec(escapeshellarg((string) $interpElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : interpolation de chaînes',
    );

    $copyElf = $compilePhp('copy', $copySource, 'elf');
    T::same(
        "2 3\n",
        shell_exec(escapeshellarg((string) $copyElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : copie de tableau',
    );

    $appendElf = $compilePhp('append', $appendSource, 'elf');
    T::same(
        "3 4\n",
        shell_exec(escapeshellarg((string) $appendElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : tableau vide puis append',
    );

    $stringLoopElf = $compilePhp('string-loop', $stringLoopSource, 'elf');
    T::same(
        "300\n",
        shell_exec(escapeshellarg((string) $stringLoopElf['binaryPath']) . ' 2>&1'),
        'runtime natif : chaînes vivantes pendant la GC',
    );

    $arrayLoopElf = $compilePhp('array-loop', $arrayLoopSource, 'elf');
    T::same(
        "200 19900\n",
        shell_exec(escapeshellarg((string) $arrayLoopElf['binaryPath']) . ' 2>&1'),
        'runtime natif : tableaux vivants pendant la GC',
    );

    $functionGcElf = $compilePhp('function-gc', <<<'PHP'
<?php
function make(int $i): string {
    return "v" . $i;
}

$result = "";
for ($i = 0; $i < 300; $i = $i + 1) {
    $result = make($i) . $result;
}
echo strlen($result), "\n";
PHP, 'elf');
    T::same(
        "1090\n",
        shell_exec(escapeshellarg((string) $functionGcElf['binaryPath']) . ' 2>&1'),
        'runtime natif : retour de chaîne à travers la GC',
    );

    $arrayStringsElf = $compilePhp('array-strings', <<<'PHP'
<?php
$items = [];
for ($i = 0; $i < 300; $i = $i + 1) {
    $items[] = "x" . $i;
}
$length = 0;
foreach ($items as $item) {
    $length = $length + strlen($item);
}
echo count($items), " ", $length, "\n";
PHP, 'elf');
    T::same(
        "300 1090\n",
        shell_exec(escapeshellarg((string) $arrayStringsElf['binaryPath']) . ' 2>&1'),
        'runtime natif : chaînes dans un tableau pendant la GC',
    );

    $floatForeachElf = $compilePhp('float-foreach', <<<'PHP'
<?php
$items = [1, 2, 3];
$value = 0.0;
foreach ($items as $value) {
    echo $value, " ";
}
echo "\n";
PHP, 'elf');
    T::same(
        "1 2 3 \n",
        shell_exec(escapeshellarg((string) $floatForeachElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : foreach int vers variable float',
    );

    $boundsElf = $compilePhp('bounds', $boundsSource, 'elf');
    $boundsOut = [];
    $boundsStatus = 0;
    exec(escapeshellarg((string) $boundsElf['binaryPath']) . ' 2>&1', $boundsOut, $boundsStatus);
    T::same(1, $boundsStatus, 'compilateur PHP : index hors limites refusé');
    T::ok(
        str_contains(implode("\n", $boundsOut), 'hors limites'),
        'compilateur PHP : message d’index hors limites',
    );

    $runtimeC = $phpRoot . '/runtime-check.c';
    file_put_contents($runtimeC, <<<'C'
#include "cyonima_runtime.h"

int main(void)
{
    cy_runtime_init();

    const char *text = cy_string_concat("a", "b");
    CyArray *items = cy_array_new();
    cy_array_push_int(items, 7);
    cy_array_push_int(items, 8);

    CyRoot roots[] = {
        {CY_ROOT_STRING, (void *) &text},
        {CY_ROOT_ARRAY, (void *) &items},
    };
    CyRootFrame frame;
    cy_roots_push(&frame, roots, 2);

    CyArray *garbage = cy_array_new();
    (void) garbage;
    cy_gc_collect();

    if (strcmp(text, "ab") != 0) {
        return 1;
    }

    if (cy_array_count(items) != 2) {
        return 2;
    }

    if (cy_array_get_int(items, 1) != 8) {
        return 3;
    }

    cy_roots_pop(&frame);
    text = NULL;
    items = NULL;
    cy_gc_collect();

    if (cy_allocations != NULL) {
        return 4;
    }

    cy_runtime_shutdown();

    return 0;
}
C);
    $runtimeBuildOut = [];
    $runtimeBuildStatus = 0;
    exec(
        'gcc -std=c11 -O2 -I ' . escapeshellarg(dirname(__DIR__) . '/runtime/native')
        . ' -o ' . escapeshellarg($phpRoot . '/runtime-check')
        . ' ' . escapeshellarg($runtimeC) . ' -lm 2>&1',
        $runtimeBuildOut,
        $runtimeBuildStatus,
    );
    T::same(0, $runtimeBuildStatus, 'runtime natif : compilation du test mémoire');
    $runtimeRunOut = [];
    $runtimeRunStatus = 0;
    exec(escapeshellarg($phpRoot . '/runtime-check'), $runtimeRunOut, $runtimeRunStatus);
    T::same(0, $runtimeRunStatus, 'runtime natif : racines GC et libération');
}

$arrayErrors = [
    ['tableau hétérogène', '<?php $a = [1, "x"];', 'tableau hétérogène'],
    ['tableau mixte', '<?php $a = [1, "x" => 2];', 'tableau mélangeant index et clés associatives'],
    ['clé associative entière', '<?php $a = [1 => 2];', 'clé associative attend string'],
    ['foreach non-tableau', '<?php $x = 1; foreach ($x as $v) {}', 'foreach attend un tableau'],
    ['foreach sans type', '<?php $a = []; foreach ($a as $v) {}', 'type de tableau inconnu'],
    ['echo de tableau', '<?php $a = [1]; echo $a;', 'echo ne peut pas afficher'],
    ['count non-tableau', '<?php $x = 1; echo count($x);', 'count attend un tableau'],
    ['index manquant', '<?php $a = [1]; echo $a[];', 'index manquant en lecture'],
    ['tableau incompatible', '<?php $a = [1]; $a = ["x"];', 'valeur array<string> refusée'],
];

foreach ($arrayErrors as $index => [$label, $source, $needle]) {
    try {
        $compilePhp('array-error-' . $index, $source, 'c');
        T::ok(false, 'compilateur PHP : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'compilateur PHP : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

// ---------------------------------------------------------------------------
T::section('17. Analyse de flux — affectation définie et retours');

if (!$hasGcc) {
    T::skip('gcc absent : exécution des contrôles de flux non testée');
} else {
    $flowOkElf = $compilePhp('flow-ok', <<<'PHP'
<?php
function choose(bool $flag): int {
    if ($flag) {
        $value = 1;
    } else {
        $value = 2;
    }
    return $value;
}
echo choose(true), " ", choose(false), "\n";
PHP, 'elf');
    T::same(
        "1 2\n",
        shell_exec(escapeshellarg((string) $flowOkElf['binaryPath']) . ' 2>&1'),
        'analyse de flux : branches complètes',
    );

    $returnOkElf = $compilePhp('return-ok', <<<'PHP'
<?php
function choose(int $value): int {
    if ($value > 0) {
        return 1;
    } else {
        return 2;
    }
}
echo choose(5), " ", choose(-1), "\n";
PHP, 'elf');
    T::same(
        "1 2\n",
        shell_exec(escapeshellarg((string) $returnOkElf['binaryPath']) . ' 2>&1'),
        'analyse de flux : retours exhaustifs',
    );

    $forAfterElf = $compilePhp('for-after', <<<'PHP'
<?php
for ($i = 0; $i < 3; $i = $i + 1) {
    $x = $i;
}
echo $i, "\n";
PHP, 'elf');
    T::same(
        "3\n",
        shell_exec(escapeshellarg((string) $forAfterElf['binaryPath']) . ' 2>&1'),
        'analyse de flux : variable de for après la boucle',
    );
}

$flowErrors = [
    ['affectation partielle', '<?php function f(bool $c): int { if ($c) { $x = 1; } return $x; }', 'peut ne pas être affectée'],
    ['retour partiel', '<?php function f(int $x): int { if ($x > 0) { return 1; } }', 'ne retourne pas sur tous les chemins'],
    ['while non garanti', '<?php $i = 0; while ($i < 3) { $x = $i; $i = $i + 1; } echo $x;', 'peut ne pas être affectée'],
    ['foreach non garanti', '<?php $a = [1]; foreach ($a as $v) {} echo $v;', 'peut ne pas être affectée'],
    ['court-circuit', '<?php function f(bool $c): void { $c && ($x = true); echo $x; }', 'peut ne pas être affectée'],
    ['top-level partiel', '<?php $c = true; if ($c) { $x = 1; } echo $x;', 'peut ne pas être affectée'],
];

foreach ($flowErrors as $index => [$label, $source, $needle]) {
    try {
        $compilePhp('flow-error-' . $index, $source, 'c');
        T::ok(false, 'analyse de flux : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'analyse de flux : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

// ---------------------------------------------------------------------------
T::section('18. Compilateur PHP — runtime UI natif GTK4');

$uiSource = <<<'PHP'
<?php
function show(int $label): void {
    ui_set_text($label, "Cliqué !");
}

function stop(): void {
    ui_quit();
}

ui_init("com.cyonima.nativeui.test");
$window = ui_window("Cyonima natif", 400, 200);
$box = ui_box(true, 8);
$label = ui_label("Bonjour");
$button = ui_button("Cliquer");
ui_append($box, $label);
ui_append($box, $button);
ui_set_child($window, $box);
ui_on_click($button, "show", $label);
ui_present($window);
ui_after(200, "stop");
ui_run();
PHP;

$uiC = $compilePhp('ui', $uiSource, 'c');
$uiCSource = (string) file_get_contents($uiC['cPath']);
T::ok(str_contains($uiCSource, '#include "cyonima_ui.h"'), 'UI native : include GTK');
T::ok(str_contains($uiCSource, 'cy_ui_window'), 'UI native : fenêtre');
T::ok(str_contains($uiCSource, 'cy_ui_button'), 'UI native : bouton');
T::ok(str_contains($uiCSource, 'cy_ui_widget_show'), 'UI native : wrapper de clic');
T::ok(str_contains($uiCSource, 'cy_ui_timer_stop'), 'UI native : wrapper de timer');
T::ok(
    str_contains($uiCSource, '(gpointer)(intptr_t)(v_label)'),
    'UI native : handle transmis au gestionnaire',
);
T::ok(str_contains($uiCSource, 'cy_ui_run()'), 'UI native : boucle GTK');

$exampleUiC = (new PhpCompiler($phpRoot))->compile(
    dirname(__DIR__) . '/examples/native-ui.php',
    null,
    'c',
);
$exampleUiSource = (string) file_get_contents($exampleUiC['cPath']);
T::ok(
    str_contains($exampleUiSource, 'cy_ui_widget_show')
    && str_contains($exampleUiSource, 'cy_ui_widget_quit'),
    'UI native : exemple du dépôt',
);

$gtkPkg = trim((string) shell_exec('pkg-config --exists gtk4 2>/dev/null && echo yes || true')) === 'yes';
$hasDisplay = getenv('DISPLAY') !== false && getenv('DISPLAY') !== '';

if (!$gtkPkg) {
    T::skip('GTK4 dev absent : exécution UI native non testée');
} elseif (!$hasDisplay) {
    T::skip('DISPLAY absent : exécution UI native non testée');
} else {
    $uiElf = $compilePhp('ui', $uiSource, 'elf');
    $runner = 'timeout 8 ' . escapeshellarg((string) $uiElf['binaryPath']);

    if (getenv('DBUS_SESSION_BUS_ADDRESS') === false
        && trim((string) shell_exec('command -v dbus-run-session 2>/dev/null')) !== '') {
        $runner = 'dbus-run-session -- ' . $runner;
    }

    $uiOut = [];
    $uiStatus = 0;
    exec($runner . ' 2>&1', $uiOut, $uiStatus);
    T::ok(
        $uiStatus === 0,
        'UI native : application GTK exécutée',
        implode("\n", $uiOut),
    );
}

$uiErrors = [
    ['gestionnaire inconnu', '<?php ui_init("x"); ui_on_click(1, "absent");', 'gestionnaire inconnu'],
    ['gestionnaire signé', '<?php function h(int $a, int $b): void {} ui_init("x"); ui_on_click(1, "h");', 'doit être déclaré'],
    ['gestionnaire retour', '<?php function h(): int { return 1; } ui_init("x"); ui_on_click(1, "h");', 'doit être déclaré'],
    ['gestionnaire variable', '<?php $name = "h"; ui_init("x"); ui_on_click(1, $name);', 'nom de fonction littéral'],
];

foreach ($uiErrors as $index => [$label, $source, $needle]) {
    try {
        $compilePhp('ui-error-' . $index, $source, 'c');
        T::ok(false, 'UI native : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'UI native : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

// ---------------------------------------------------------------------------
T::section('19. Compilateur PHP — tableaux associatifs');

$mapSource = <<<'PHP'
<?php
$ages = ['alice' => 30, 'bob' => 25];
$ages['carol'] = 40;
$total = 0;
foreach ($ages as $name => $age) {
    echo $name, "=", $age, " ";
    $total = $total + $age;
}
echo "\n", count($ages), " ", $total, "\n";
echo $ages['bob'], "\n";
PHP;

$mapC = $compilePhp('map', $mapSource, 'c');
$mapCSource = (string) file_get_contents($mapC['cPath']);
T::ok(str_contains($mapCSource, 'cy_map_set_string_int'), 'backend PHP : écriture associative');
T::ok(str_contains($mapCSource, 'cy_map_get_string_int'), 'backend PHP : lecture associative');
T::ok(str_contains($mapCSource, 'cy_map_key_string'), 'backend PHP : clé foreach');
T::ok(str_contains($mapCSource, 'cy_map_value_int'), 'backend PHP : valeur foreach');
T::ok(str_contains($mapCSource, 'cy_map_count'), 'backend PHP : count associatif');
T::ok(str_contains($mapCSource, 'CY_ROOT_MAP'), 'runtime natif : racine de table associative');

if (!$hasGcc) {
    T::skip('gcc absent : exécution des tableaux associatifs non testée');
} else {
    $mapElf = $compilePhp('map', $mapSource, 'elf');
    T::same(
        "alice=30 bob=25 carol=40 \n3 95\n25\n",
        shell_exec(escapeshellarg((string) $mapElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : table associative, foreach et count',
    );

    $mapCopyElf = $compilePhp('map-copy', <<<'PHP'
<?php
$a = ['x' => 1];
$b = $a;
$b['x'] = 9;
$b['y'] = 2;
echo $a['x'], " ", count($a), " ", count($b), "\n";
PHP, 'elf');
    T::same(
        "1 1 2\n",
        shell_exec(escapeshellarg((string) $mapCopyElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : copie de table associative',
    );

    $mapEmptyElf = $compilePhp('map-empty', <<<'PHP'
<?php
$data = [];
$data['x'] = 1;
$data['y'] = 2;
echo count($data), " ", $data['y'], "\n";
PHP, 'elf');
    T::same(
        "2 2\n",
        shell_exec(escapeshellarg((string) $mapEmptyElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : tableau vide raffiné en table associative',
    );

    $mapStringsElf = $compilePhp('map-strings', <<<'PHP'
<?php
$names = [];
for ($i = 0; $i < 300; $i = $i + 1) {
    $names["k" . $i] = "v" . $i;
}
$length = 0;
foreach ($names as $key => $value) {
    $length = $length + strlen($key) + strlen($value);
}
echo count($names), " ", $length, "\n";
PHP, 'elf');
    T::same(
        "300 2180\n",
        shell_exec(escapeshellarg((string) $mapStringsElf['binaryPath']) . ' 2>&1'),
        'runtime natif : table associative de chaînes pendant la GC',
    );

    $floatMapElf = $compilePhp('map-float', <<<'PHP'
<?php
$key = "pi";
$values = [$key => 3.5, "e" => 2.5];
echo $values[$key], " ", count($values), "\n";
foreach ($values as $value) {
    echo $value, " ";
}
echo "\n";
PHP, 'elf');
    T::same(
        "3.5 2\n3.5 2.5 \n",
        shell_exec(escapeshellarg((string) $floatMapElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : table associative de flottants',
    );

    $boolMapElf = $compilePhp('map-bool', <<<'PHP'
<?php
$flags = ['a' => true, 'b' => false];
foreach ($flags as $key => $value) {
    echo $key, "=", $value, " ";
}
echo "\n";
PHP, 'elf');
    T::same(
        "a=1 b= \n",
        shell_exec(escapeshellarg((string) $boolMapElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : table associative de booléens',
    );

    $exampleMapElf = (new PhpCompiler($phpRoot))->compile(
        dirname(__DIR__) . '/examples/native-maps.php',
        null,
        'elf',
    );
    T::same(
        "alice : 30\nbob : 25\ncarol : 40\nTotal : 95\n",
        shell_exec(escapeshellarg((string) $exampleMapElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : exemple tableaux associatifs',
    );

    $missingMapElf = $compilePhp('map-missing', <<<'PHP'
<?php
$a = ["x" => 1];
echo $a["y"];
PHP, 'elf');
    $missingOut = [];
    $missingStatus = 0;
    exec(
        escapeshellarg((string) $missingMapElf['binaryPath']) . ' 2>&1',
        $missingOut,
        $missingStatus,
    );
    T::same(1, $missingStatus, 'compilateur PHP : clé absente refusée');
    T::ok(
        str_contains(implode("\n", $missingOut), 'clé absente'),
        'compilateur PHP : message de clé absente',
    );
}

$mapErrors = [
    ['tableau mixte', '<?php $a = [1, "x" => 2];', 'tableau mélangeant index et clés associatives'],
    ['clé entière', '<?php $a = [1 => 2];', 'clé associative attend string'],
    ['append associatif', '<?php $a = ["x" => 1]; $a[] = 2;', 'ajout [] interdit sur un tableau associatif'],
    ['clé sur liste', '<?php $a = [1, 2]; echo $a["x"];', 'index de tableau attend int'],
    ['index entier sur map', '<?php $a = ["x" => 1]; echo $a[0];', 'clé associative attend string'],
    ['valeur incompatible', '<?php $a = ["x" => 1]; $a["x"] = "y";', 'affectation de tableau attend int'],
    ['clé foreach incompatible', '<?php $a = ["x" => 1]; $k = 0; foreach ($a as $k => $v) {}', 'clé string attendue'],
];

foreach ($mapErrors as $index => [$label, $source, $needle]) {
    try {
        $compilePhp('map-error-' . $index, $source, 'c');
        T::ok(false, 'compilateur PHP : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'compilateur PHP : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

// ---------------------------------------------------------------------------
T::section('20. Compilateur PHP — switch, match et isset');

$switchSource = <<<'PHP'
<?php
$value = 2;
switch ($value) {
    case 1:
        echo "un\n";
        break;
    case 2:
    case 3:
        echo "deux ou trois\n";
        break;
    default:
        echo "autre\n";
}
PHP;

$switchC = $compilePhp('switch', $switchSource, 'c');
$switchCSource = (string) file_get_contents($switchC['cPath']);
T::ok(str_contains($switchCSource, 'goto L'), 'backend PHP : switch abaissé en sauts');

$issetSource = <<<'PHP'
<?php
$a = [1, 2];
$m = ['x' => 1];
echo isset($a[0]), " ", isset($a[5]), " ", isset($m['x']), " ", isset($m['y']), "\n";
PHP;

$issetC = $compilePhp('isset', $issetSource, 'c');
$issetCSource = (string) file_get_contents($issetC['cPath']);
T::ok(str_contains($issetCSource, 'cy_array_has_int'), 'backend PHP : isset indexé');
T::ok(str_contains($issetCSource, 'cy_map_has_string'), 'backend PHP : isset associatif');

if (!$hasGcc) {
    T::skip('gcc absent : exécution de switch/match/isset non testée');
} else {
    $switchElf = $compilePhp('switch', $switchSource, 'elf');
    T::same(
        "deux ou trois\n",
        shell_exec(escapeshellarg((string) $switchElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : switch et fall-through',
    );

    $fallElf = $compilePhp('switch-fall', <<<'PHP'
<?php
switch (1) {
    case 1:
        echo "a";
    case 2:
        echo "b";
        break;
    default:
        echo "c";
}
echo "\n";
PHP, 'elf');
    T::same(
        "ab\n",
        shell_exec(escapeshellarg((string) $fallElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : fall-through switch',
    );

    $defaultMiddleElf = $compilePhp('switch-default-middle', <<<'PHP'
<?php
switch (5) {
    case 1:
        echo "1";
        break;
    default:
        echo "d";
    case 2:
        echo "2";
        break;
}
echo "\n";
PHP, 'elf');
    T::same(
        "d2\n",
        shell_exec(escapeshellarg((string) $defaultMiddleElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : default au milieu du switch',
    );

    $returnSwitchElf = $compilePhp('switch-return', <<<'PHP'
<?php
function label(int $x): int {
    switch ($x) {
        case 1:
            return 10;
        default:
            return 20;
    }
}
echo label(1), " ", label(5), "\n";
PHP, 'elf');
    T::same(
        "10 20\n",
        shell_exec(escapeshellarg((string) $returnSwitchElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : retours exhaustifs via switch',
    );

    $matchElf = $compilePhp('match', <<<'PHP'
<?php
$x = 2;
$label = match ($x) {
    1, 2 => "petit",
    3 => "trois",
    default => "autre",
};
echo $label, "\n";
$name = "b";
$result = match ($name) {
    "a" => 1,
    "b" => 2,
    default => 0,
};
echo $result, "\n";
PHP, 'elf');
    T::same(
        "petit\n2\n",
        shell_exec(escapeshellarg((string) $matchElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : match entier et chaîne',
    );

    $issetElf = $compilePhp('isset', $issetSource, 'elf');
    T::same(
        "1  1 \n",
        shell_exec(escapeshellarg((string) $issetElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : isset indexé et associatif',
    );
}

$flowErrors = [
    ['break hors switch', '<?php break;', 'break autorisé uniquement dans un switch'],
    ['default switch dupliqué', '<?php switch (1) { default: break; default: break; }', 'plusieurs blocs default'],
    ['case incompatible', '<?php switch (1) { case "x": break; }', 'case de type string incompatible'],
    ['switch tableau', '<?php switch ([1]) { case 1: break; }', 'switch attend une valeur scalaire'],
    ['match sans default', '<?php $x = 1; echo match ($x) { 1 => "a" };', 'match doit avoir une branche default'],
    ['match default dupliqué', '<?php $x = 1; echo match ($x) { default => "a", default => "b" };', 'plusieurs branches default'],
    ['match condition incompatible', '<?php $x = 1; echo match ($x) { "1" => "a", default => "b" };', 'condition match de type string'],
    ['match branches incompatibles', '<?php $x = 1; echo match ($x) { 1 => 2, default => "b" };', 'types de branches match incompatibles'],
    ['isset sans index', '<?php $a = [1]; echo isset($a);', 'isset attend une expression'],
    ['isset mauvaise clé', '<?php $a = [1]; echo isset($a["x"]);', 'isset (index de tableau) attend int'],
    ['switch retour partiel', '<?php function f(int $x): int { switch ($x) { case 1: break; default: return 2; } }', 'ne retourne pas sur tous les chemins'],
];

foreach ($flowErrors as $index => [$label, $source, $needle]) {
    try {
        $compilePhp('control-error-' . $index, $source, 'c');
        T::ok(false, 'compilateur PHP : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'compilateur PHP : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

// ---------------------------------------------------------------------------
T::section('21. Compilateur PHP — exceptions try/throw/catch');

$exceptionSource = <<<'PHP'
<?php
try {
    throw "boom";
    echo "jamais";
} catch (string $e) {
    echo "capturé: ", $e, "\n";
}
PHP;

$exceptionC = $compilePhp('exception', $exceptionSource, 'c');
$exceptionCSource = (string) file_get_contents($exceptionC['cPath']);
T::ok(str_contains($exceptionCSource, 'cy_throw_exception'), 'backend PHP : throw');
T::ok(str_contains($exceptionCSource, 'cy_exception_capture'), 'backend PHP : capture catch');

$propagateSource = <<<'PHP'
<?php
function fail(): void {
    throw "échec interne";
}

try {
    fail();
    echo "jamais";
} catch (string $e) {
    echo "capturé: ", $e, "\n";
}
PHP;
$propagateC = $compilePhp('exception-propagate', $propagateSource, 'c');
T::ok(
    str_contains((string) file_get_contents($propagateC['cPath']), 'cy_exception_pending'),
    'backend PHP : propagation exception',
);

if (!$hasGcc) {
    T::skip('gcc absent : exécution des exceptions non testée');
} else {
    $exceptionElf = $compilePhp('exception', $exceptionSource, 'elf');
    T::same(
        "capturé: boom\n",
        shell_exec(escapeshellarg((string) $exceptionElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : throw et catch',
    );

    $propagateElf = $compilePhp('exception-propagate', $propagateSource, 'elf');
    T::same(
        "capturé: échec interne\n",
        shell_exec(escapeshellarg((string) $propagateElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : propagation entre fonctions',
    );

    $normalElf = $compilePhp('exception-normal', <<<'PHP'
<?php
try {
    echo "ok";
} catch (string $e) {
    echo "bad";
}
echo "!\n";
PHP, 'elf');
    T::same(
        "ok!\n",
        shell_exec(escapeshellarg((string) $normalElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : catch non exécuté sans exception',
    );

    $nestedElf = $compilePhp('exception-nested', <<<'PHP'
<?php
try {
    try {
        throw "interne";
    } catch (string $e) {
        echo "interne: ", $e, "\n";
        throw "externe";
    }
} catch (string $e) {
    echo "externe: ", $e, "\n";
}
PHP, 'elf');
    T::same(
        "interne: interne\nexterne: externe\n",
        shell_exec(escapeshellarg((string) $nestedElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : try/catch imbriqués',
    );

    $loopElf = $compilePhp('exception-loop', <<<'PHP'
<?php
$i = 0;
try {
    while ($i < 5) {
        if ($i == 2) {
            throw "stop";
        }
        $i = $i + 1;
    }
} catch (string $e) {
    echo $i, " ", $e, "\n";
}
PHP, 'elf');
    T::same(
        "2 stop\n",
        shell_exec(escapeshellarg((string) $loopElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : exception sortant d’une boucle',
    );

    $gcExceptionElf = $compilePhp('exception-gc', <<<'PHP'
<?php
try {
    $message = "";
    for ($i = 0; $i < 300; $i = $i + 1) {
        $message = $message . "x";
    }
    throw $message;
} catch (string $e) {
    echo strlen($e), "\n";
}
PHP, 'elf');
    T::same(
        "300\n",
        shell_exec(escapeshellarg((string) $gcExceptionElf['binaryPath']) . ' 2>&1'),
        'runtime natif : message d’exception survit à la GC',
    );

    $exampleExceptionElf = (new PhpCompiler($phpRoot))->compile(
        dirname(__DIR__) . '/examples/native-exceptions.php',
        null,
        'elf',
    );
    T::same(
        "6\nErreur : valeur négative\n",
        shell_exec(escapeshellarg((string) $exampleExceptionElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : exemple exceptions',
    );

    $returnElf = $compilePhp('exception-return', <<<'PHP'
<?php
function f(int $x): int {
    try {
        if ($x > 0) {
            return 1;
        }
        throw "negatif";
    } catch (string $e) {
        return 2;
    }
}
echo f(1), " ", f(-1), "\n";
PHP, 'elf');
    T::same(
        "1 2\n",
        shell_exec(escapeshellarg((string) $returnElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : retours via try/catch',
    );

    $uncaughtElf = $compilePhp('exception-uncaught', <<<'PHP'
<?php
throw "fatal";
PHP, 'elf');
    $uncaughtOut = [];
    $uncaughtStatus = 0;
    exec(
        escapeshellarg((string) $uncaughtElf['binaryPath']) . ' 2>&1',
        $uncaughtOut,
        $uncaughtStatus,
    );
    T::same(1, $uncaughtStatus, 'compilateur PHP : exception non capturée');
    T::ok(
        str_contains(implode("\n", $uncaughtOut), 'exception non capturée')
        && str_contains(implode("\n", $uncaughtOut), 'fatal'),
        'compilateur PHP : message d’exception non capturée',
    );
}

$exceptionErrors = [
    ['throw non-string', '<?php throw 1;', 'throw attend une chaîne ou une Exception'],
    ['catch non-string', '<?php try { echo "x"; } catch (int $e) { echo $e; }', 'catch attend string ou une Exception'],
    ['try sans catch', '<?php try { echo "x"; }', 'try doit avoir au moins un catch ou un finally'],
    ['finally hors try', '<?php finally { echo "f"; }', 'finally doit suivre try/catch'],
    ['catch après try', '<?php try { echo "x"; } catch (string $e) { echo "c"; } echo $e;', 'peut ne pas être affectée'],
    ['retour partiel', '<?php function f(): int { try { throw "x"; } catch (string $e) { echo $e; } }', 'ne retourne pas sur tous les chemins'],
];

foreach ($exceptionErrors as $index => [$label, $source, $needle]) {
    try {
        $compilePhp('exception-error-' . $index, $source, 'c');
        T::ok(false, 'compilateur PHP : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'compilateur PHP : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

// ---------------------------------------------------------------------------
T::section('22. Compilateur PHP — classes, objets et méthodes');

$pointSource = <<<'PHP'
<?php
class Point {
    public int $x;
    public int $y;

    public function __construct(int $x, int $y): void {
        $this->x = $x;
        $this->y = $y;
    }

    public function sum(): int {
        return $this->x + $this->y;
    }

    public function move(int $dx, int $dy): void {
        $this->x = $this->x + $dx;
        $this->y = $this->y + $dy;
    }
}

$p = new Point(1, 2);
echo $p->sum(), "\n";
$p->move(3, 4);
echo $p->x, " ", $p->y, " ", $p->sum(), "\n";
$q = $p;
$q->x = 100;
echo $p->x, "\n";
PHP;

$pointC = $compilePhp('class-point', $pointSource, 'c');
$pointCSource = (string) file_get_contents($pointC['cPath']);
T::ok(str_contains($pointCSource, 'CyObj_Point'), 'backend PHP : struct de classe');
T::ok(str_contains($pointCSource, 'cy_object_new'), 'backend PHP : allocation objet');
T::ok(str_contains($pointCSource, 'p_x'), 'backend PHP : propriété C');
T::ok(str_contains($pointCSource, 'fn_Point__sum'), 'backend PHP : méthode C');
T::ok(str_contains($pointCSource, 'CY_ROOT_OBJECT'), 'runtime natif : racine objet');

if (!$hasGcc) {
    T::skip('gcc absent : exécution des classes non testée');
} else {
    $pointElf = $compilePhp('class-point', $pointSource, 'elf');
    T::same(
        "3\n4 6 10\n100\n",
        shell_exec(escapeshellarg((string) $pointElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : constructeur, méthodes et propriétés',
    );

    $personElf = $compilePhp('class-person', <<<'PHP'
<?php
class Person {
    public string $name;

    public function __construct(string $name): void {
        $this->name = $name;
    }

    public function greet(): string {
        return "Bonjour " . $this->name;
    }
}

$p = new Person("Cyonima");
echo $p->greet(), "\n";
$p->name = "IDE";
echo $p->greet(), "\n";
PHP, 'elf');
    T::same(
        "Bonjour Cyonima\nBonjour IDE\n",
        shell_exec(escapeshellarg((string) $personElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : propriété chaîne',
    );

    $methodsElf = $compilePhp('class-methods', <<<'PHP'
<?php
class Counter {
    public int $value;

    public function __construct(int $start): void {
        $this->value = $start;
    }

    public function add(int $amount): void {
        $this->value = $this->value + $amount;
    }

    public function twice(): int {
        return $this->value * 2;
    }

    public function bump(): void {
        $this->add(1);
    }
}

$c = new Counter(5);
$c->bump();
$c->add(4);
echo $c->value, " ", $c->twice(), "\n";
PHP, 'elf');
    T::same(
        "10 20\n",
        shell_exec(escapeshellarg((string) $methodsElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : méthode appelant une méthode',
    );

    $methodThrowElf = $compilePhp('class-throw', <<<'PHP'
<?php
class Failure {
    public function fail(): void {
        throw "méthode en échec";
    }
}

$f = new Failure();
try {
    $f->fail();
    echo "jamais";
} catch (string $e) {
    echo $e, "\n";
}
PHP, 'elf');
    T::same(
        "méthode en échec\n",
        shell_exec(escapeshellarg((string) $methodThrowElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : exception depuis une méthode',
    );

    $constructorThrowElf = $compilePhp('class-ctor-throw', <<<'PHP'
<?php
class Broken {
    public function __construct(): void {
        throw "constructeur";
    }
}

try {
    $b = new Broken();
} catch (string $e) {
    echo $e, "\n";
}
PHP, 'elf');
    T::same(
        "constructeur\n",
        shell_exec(escapeshellarg((string) $constructorThrowElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : exception depuis un constructeur',
    );

    $objectGcElf = $compilePhp('class-gc', <<<'PHP'
<?php
class Item {
    public string $name;

    public function __construct(string $name): void {
        $this->name = $name;
    }

    public function length(): int {
        return strlen($this->name);
    }
}

$total = 0;
for ($i = 0; $i < 300; $i = $i + 1) {
    $item = new Item("x" . $i);
    $total = $total + $item->length();
}
echo $total, "\n";
PHP, 'elf');
    T::same(
        "1090\n",
        shell_exec(escapeshellarg((string) $objectGcElf['binaryPath']) . ' 2>&1'),
        'runtime natif : objets et propriétés chaîne pendant la GC',
    );

    $nestedElf = $compilePhp('class-nested', <<<'PHP'
<?php
class Point {
    public int $x;
    public int $y;

    public function __construct(int $x, int $y): void {
        $this->x = $x;
        $this->y = $y;
    }

    public function sum(): int {
        return $this->x + $this->y;
    }
}

class Factory {
    public function make(int $x, int $y): int {
        $p = new Point($x, $y);
        return $p->sum();
    }
}

function build(int $x, int $y): int {
    $p = new Point($x, $y);
    return $p->sum();
}

$f = new Factory();
echo $f->make(3, 4), " ", build(5, 6), "\n";
PHP, 'elf');
    T::same(
        "7 11\n",
        shell_exec(escapeshellarg((string) $nestedElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : objets locaux dans fonctions et méthodes',
    );

    $exampleClassElf = (new PhpCompiler($phpRoot))->compile(
        dirname(__DIR__) . '/examples/native-classes.php',
        null,
        'elf',
    );
    T::same(
        "8\n",
        shell_exec(escapeshellarg((string) $exampleClassElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : exemple classes',
    );
}

$classErrors = [
    ['classe inconnue', '<?php $x = new Missing();', 'classe inconnue'],
    ['propriété inconnue', '<?php class A { public int $x; } $a = new A(); echo $a->y;', 'propriété inconnue'],
    ['méthode inconnue', '<?php class A { public int $x; } $a = new A(); $a->missing();', 'méthode inconnue'],
    ['constructeur manquant', '<?php class A { public int $x; } $a = new A(1);', 'n’a pas de constructeur'],
    ['constructeur arguments', '<?php class A { public function __construct(int $x): void {} } $a = new A();', 'attend 1 argument'],
    ['constructeur type', '<?php class A { public function __construct(int $x): void {} } $a = new A("x");', 'attend int, reçu string'],
    ['this hors méthode', '<?php echo $this->x;', 'variable non définie'],
    ['this affecté', '<?php class A { public int $x; function f(): void { $this = 1; } }', 'affectation à $this interdite'],
    ['echo objet', '<?php class A { public int $x; } $a = new A(); echo $a;', 'echo ne peut pas afficher'],
    ['opérateur objet', '<?php class A { public int $x; } $a = new A(); $b = new A(); echo $a == $b;', 'non supporté sur un objet'],
    ['propriété par défaut', '<?php class A { public int $x = 1; }', 'valeur par défaut de propriété non supportée'],
    ['classe réservée', '<?php class array { public int $x; }', 'nom de classe réservé'],
    ['propriété dupliquée', '<?php class A { public int $x; public int $x; }', 'propriété dupliquée'],
    ['méthode retour partiel', '<?php class A { public int $x; function f(int $v): int { if ($v > 0) { return 1; } } }', 'ne retourne pas sur tous les chemins'],
];

foreach ($classErrors as $index => [$label, $source, $needle]) {
    try {
        $compilePhp('class-error-' . $index, $source, 'c');
        T::ok(false, 'compilateur PHP : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'compilateur PHP : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

// ---------------------------------------------------------------------------
T::section('23. Compilateur PHP — closures et callbacks');

$closureSource = <<<'PHP'
<?php
$factor = 3;
$fn = function (int $x) use ($factor): int {
    return $x * $factor;
};
echo $fn(4), "\n";
PHP;

$closureC = $compilePhp('closure-use', $closureSource, 'c');
$closureCSource = (string) file_get_contents($closureC['cPath']);
T::ok(str_contains($closureCSource, 'CyClosureData_'), 'backend PHP : structure de closure');
T::ok(str_contains($closureCSource, 'cy_closure_mark_'), 'backend PHP : marquage de closure');
T::ok(str_contains($closureCSource, '->invoke'), 'backend PHP : fonction invoke');
T::ok(str_contains($closureCSource, 'cap_factor'), 'backend PHP : capture de variable');

if (!$hasGcc) {
    T::skip('gcc absent : exécution des closures non testée');
} else {
    $closureElf = $compilePhp('closure-use', $closureSource, 'elf');
    T::same(
        "12\n",
        shell_exec(escapeshellarg((string) $closureElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : closure avec use',
    );

    $arrowElf = $compilePhp('closure-arrow', <<<'PHP'
<?php
$factor = 3;
$fn = fn (int $x): int => $x * $factor;
echo $fn(4), "\n";
PHP, 'elf');
    T::same(
        "12\n",
        shell_exec(escapeshellarg((string) $arrowElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : arrow function',
    );

    $inferElf = $compilePhp('closure-infer', <<<'PHP'
<?php
$base = 10;
$add = fn (int $x) => $x + $base;
echo $add(5), "\n";
PHP, 'elf');
    T::same(
        "15\n",
        shell_exec(escapeshellarg((string) $inferElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : retour de arrow function inféré',
    );

    $arrayClosureElf = $compilePhp('closure-array', <<<'PHP'
<?php
$values = [1, 2, 3];
$sum = function () use ($values): int {
    $total = 0;
    foreach ($values as $value) {
        $total = $total + $value;
    }
    return $total;
};
echo $sum(), "\n";
PHP, 'elf');
    T::same(
        "6\n",
        shell_exec(escapeshellarg((string) $arrayClosureElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : closure capturant un tableau',
    );

    $stringClosureElf = $compilePhp('closure-string', <<<'PHP'
<?php
$name = "Cyonima";
$greet = fn () => "Bonjour " . $name;
echo $greet(), "\n";
PHP, 'elf');
    T::same(
        "Bonjour Cyonima\n",
        shell_exec(escapeshellarg((string) $stringClosureElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : closure capturant une chaîne',
    );

    $nestedClosureElf = $compilePhp('closure-nested', <<<'PHP'
<?php
$add = fn (int $x): int => $x + 1;
$twice = fn (int $x): int => $add($add($x));
echo $twice(3), "\n";
PHP, 'elf');
    T::same(
        "5\n",
        shell_exec(escapeshellarg((string) $nestedClosureElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : closure capturant une closure',
    );

    $closureThrowElf = $compilePhp('closure-throw', <<<'PHP'
<?php
$fail = function (): void {
    throw "boom";
};
try {
    $fail();
} catch (string $e) {
    echo $e, "\n";
}
PHP, 'elf');
    T::same(
        "boom\n",
        shell_exec(escapeshellarg((string) $closureThrowElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : exception depuis une closure',
    );

    $closureCopyElf = $compilePhp('closure-copy', <<<'PHP'
<?php
$values = [1];
$fn = function () use ($values): int {
    $values[] = 2;
    return count($values);
};
echo $fn(), " ", count($values), "\n";
PHP, 'elf');
    T::same(
        "2 1\n",
        shell_exec(escapeshellarg((string) $closureCopyElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : capture de tableau par valeur',
    );

    $closureStateElf = $compilePhp('closure-state', <<<'PHP'
<?php
$count = 0;
$next = function () use ($count): int {
    $count = $count + 1;
    return $count;
};
echo $next(), $next(), $next(), "\n";
PHP, 'elf');
    T::same(
        "123\n",
        shell_exec(escapeshellarg((string) $closureStateElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : état capturé persistant',
    );

    $closureGcElf = $compilePhp('closure-gc', <<<'PHP'
<?php
$total = 0;
for ($i = 0; $i < 300; $i = $i + 1) {
    $prefix = "x" . $i;
    $fn = function (string $suffix) use ($prefix): string {
        return $prefix . $suffix;
    };
    $result = $fn("!");
    $total = $total + strlen($result);
}
echo $total, "\n";
PHP, 'elf');
    T::same(
        "1390\n",
        shell_exec(escapeshellarg((string) $closureGcElf['binaryPath']) . ' 2>&1'),
        'runtime natif : closures et captures pendant la GC',
    );

    $exampleClosureElf = (new PhpCompiler($phpRoot))->compile(
        dirname(__DIR__) . '/examples/native-closures.php',
        null,
        'elf',
    );
    T::same(
        "12 7 2\n",
        shell_exec(escapeshellarg((string) $exampleClosureElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : exemple closures',
    );
}

$closureErrors = [
    ['retour requis', '<?php $f = function () { return 1; };', 'type de retour requis'],
    ['capture inconnue', '<?php $f = function () use ($missing): int { return 1; };', 'variable capturée non définie'],
    ['capture arrow inconnue', '<?php $f = fn () => $missing;', 'variable capturée non définie'],
    ['appel non-callable', '<?php $x = 1; echo $x();', 'closure attendue'],
    ['arguments manquants', '<?php $f = fn (int $x): int => $x; echo $f();', 'closure attend 1 argument'],
    ['argument invalide', '<?php $f = fn (int $x): int => $x; echo $f("x");', 'appel de closure attend int'],
    ['retour partiel', '<?php $f = function (int $x): int { if ($x > 0) { return 1; } };', 'ne retourne pas sur tous les chemins'],
    ['arrow non scalaire', '<?php $f = fn () => [1, 2];', 'retour de arrow function non scalaire'],
    ['echo closure', '<?php $f = fn (): int => 1; echo $f;', 'echo ne peut pas afficher'],
    ['opérateur closure', '<?php $f = fn (): int => 1; $g = fn (): int => 2; echo $f == $g;', 'non supporté sur une closure'],
    ['closure dans tableau', '<?php $f = fn (): int => 1; $a = [$f];', 'élément de tableau non scalaire'],
];

foreach ($closureErrors as $index => [$label, $source, $needle]) {
    try {
        $compilePhp('closure-error-' . $index, $source, 'c');
        T::ok(false, 'compilateur PHP : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'compilateur PHP : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

// ---------------------------------------------------------------------------
T::section('24. Façade objet UI — Application/Window/Button');

$facadeSource = <<<'PHP'
<?php
$app = new Application('com.cyonima.facade.test');
$window = new Window('Cyonima facade', 400, 200);
$box = new Box(true, 8);
$label = new Label('Bonjour');
$button = new Button('Cliquer');
$box->append($label);
$box->append($button);
$window->setChild($box);
$button->onClicked(function () use ($label): void {
    $label->setText('Cliqué !');
    echo "clicked\n";
});
$window->present();
$app->after(100, fn () => $button->click());
$app->after(300, fn () => $app->quit());
$app->run();
PHP;

$facadeC = $compilePhp('ui-facade', $facadeSource, 'c');
$facadeCSource = (string) file_get_contents($facadeC['cPath']);
T::ok(str_contains($facadeCSource, 'CyObj_Application'), 'façade UI : classe Application');
T::ok(str_contains($facadeCSource, 'CyObj_Window'), 'façade UI : classe Window');
T::ok(str_contains($facadeCSource, 'CyObj_Button'), 'façade UI : classe Button');
T::ok(str_contains($facadeCSource, 'cy_ui_on_click_closure'), 'façade UI : callback de clic');
T::ok(str_contains($facadeCSource, 'CyClosureData_'), 'façade UI : closure de callback');
T::ok(str_contains($facadeCSource, 'cap_label'), 'façade UI : capture du widget');

$exampleFacadeC = (new PhpCompiler($phpRoot))->compile(
    dirname(__DIR__) . '/examples/native-ui-object.php',
    null,
    'c',
);
T::ok(
    str_contains(
        (string) file_get_contents($exampleFacadeC['cPath']),
        'cy_ui_on_click_closure',
    ),
    'façade UI : exemple du dépôt',
);

$gtkPkg = trim((string) shell_exec('pkg-config --exists gtk4 2>/dev/null && echo yes || true')) === 'yes';
$hasDisplay = getenv('DISPLAY') !== false && getenv('DISPLAY') !== '';

if (!$gtkPkg) {
    T::skip('GTK4 dev absent : exécution de la façade UI non testée');
} elseif (!$hasDisplay) {
    T::skip('DISPLAY absent : exécution de la façade UI non testée');
} else {
    $facadeElf = $compilePhp('ui-facade', $facadeSource, 'elf');
    $runner = 'env GTK_USE_PORTAL=0 NO_AT_BRIDGE=1 GDK_BACKEND=x11 timeout 8 '
        . escapeshellarg((string) $facadeElf['binaryPath']);

    if (getenv('DBUS_SESSION_BUS_ADDRESS') === false
        && trim((string) shell_exec('command -v dbus-run-session 2>/dev/null')) !== '') {
        $runner = 'dbus-run-session -- ' . $runner;
    }

    $facadeOut = [];
    $facadeStatus = 0;
    exec($runner . ' 2>&1', $facadeOut, $facadeStatus);
    $facadeOutput = implode("\n", $facadeOut);
    T::ok(
        $facadeStatus === 0 && str_contains($facadeOutput, 'clicked'),
        'façade UI : clic natif et callback closure',
        $facadeOutput,
    );
}

$facadeErrors = [
    [
        'callback incompatible',
        '<?php $app = new Application("x"); $b = new Button("x"); $b->onClicked(fn (int $x): int => $x);',
        'callable():void',
    ],
    [
        'classe UI inconnue sans prelude',
        '<?php $x = new MissingWidget("x");',
        'classe inconnue',
    ],
];

foreach ($facadeErrors as $index => [$label, $source, $needle]) {
    try {
        $compilePhp('facade-error-' . $index, $source, 'c');
        T::ok(false, 'façade UI : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'façade UI : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

// ---------------------------------------------------------------------------
T::section('25. Compilateur PHP — héritage et objets en signatures');

$inheritanceSource = <<<'PHP'
<?php
class Animal {
    public string $name;

    public function __construct(string $name) {
        $this->name = $name;
    }

    public function speak(): string {
        return "...";
    }

    public function label(): string {
        return $this->name;
    }
}

class Dog extends Animal {
    public function speak(): string {
        return "Woof";
    }
}

class Cat extends Animal {
    public function speak(): string {
        return "Miaou";
    }
}

function describe(Animal $animal): string {
    return $animal->label() . ": " . $animal->speak();
}

function make(string $kind): Animal {
    if ($kind == "dog") {
        return new Dog("Rex");
    }
    return new Cat("Minou");
}

$dog = new Dog("Rex");
$cat = new Cat("Minou");
echo describe($dog), "\n";
echo describe($cat), "\n";
echo describe(make("dog")), "\n";
echo describe(make("cat")), "\n";
PHP;

$inheritanceC = $compilePhp('inheritance', $inheritanceSource, 'c');
$inheritanceCSource = (string) file_get_contents($inheritanceC['cPath']);
T::ok(str_contains($inheritanceCSource, 'CyVTable_Animal'), 'héritage : vtable parent');
T::ok(str_contains($inheritanceCSource, 'CyVTable_Dog'), 'héritage : vtable enfant');
T::ok(str_contains($inheritanceCSource, 'cy_vtable_Dog'), 'héritage : instance de vtable');
T::ok(str_contains($inheritanceCSource, 'cy_vtable'), 'héritage : champ vtable');

if (!$hasGcc) {
    T::skip('gcc absent : exécution de l’héritage non testée');
} else {
    $inheritanceElf = $compilePhp('inheritance', $inheritanceSource, 'elf');
    T::same(
        "Rex: Woof\nMinou: Miaou\nRex: Woof\nMinou: Miaou\n",
        shell_exec(escapeshellarg((string) $inheritanceElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : héritage, override et dispatch dynamique',
    );

    $objectPropertyElf = $compilePhp('inheritance-property', <<<'PHP'
<?php
class Animal {
    public string $name;
    public function __construct(string $name) { $this->name = $name; }
    public function speak(): string { return "..."; }
}
class Dog extends Animal {
    public function speak(): string { return "Woof"; }
}
class Owner {
    public Animal $pet;
    public function setPet(Animal $pet): void { $this->pet = $pet; }
    public function label(): string { return $this->pet->speak(); }
}
$owner = new Owner();
$owner->setPet(new Dog("Rex"));
echo $owner->label(), "\n";
PHP, 'elf');
    T::same(
        "Woof\n",
        shell_exec(escapeshellarg((string) $objectPropertyElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : propriété objet et dispatch',
    );

    $inheritedConstructorElf = $compilePhp('inheritance-constructor', <<<'PHP'
<?php
class Base {
    public string $name;
    public function __construct(string $name) { $this->name = $name; }
    public function name(): string { return $this->name; }
}
class Child extends Base {}
echo (new Child("X"))->name(), "\n";
PHP, 'elf');
    T::same(
        "X\n",
        shell_exec(escapeshellarg((string) $inheritedConstructorElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : constructeur hérité',
    );
}

$inheritanceErrors = [
    ['parent inconnu', '<?php class A extends Missing {}', 'classe parente inconnue'],
    ['cycle héritage', '<?php class A extends B {} class B extends A {}', 'cycle d’héritage'],
    ['propriété redéclarée', '<?php class A { public int $x; } class B extends A { public int $x; }', 'propriété déjà définie'],
    ['override incompatible', '<?php class A { function f(int $x): int { return $x; } } class B extends A { function f(string $x): int { return 1; } }', 'signature incompatible'],
    ['parent vers enfant', '<?php class A {} class B extends A {} function f(B $b): void {} f(new A());', 'attend B, reçu A'],
    ['assignation parent vers enfant', '<?php class A {} class B extends A {} $b = new B(); $a = new A(); $b = $a;', 'valeur A refusée'],
    ['type inconnu', '<?php function f(Missing $x): void {}', 'type inconnu'],
    ['constructeur hérité incomplet', '<?php class A { function __construct(int $x): void {} } class B extends A {} new B();', 'attend 1 argument'],
    ['retour incompatible', '<?php class A {} class B extends A {} function f(): B { return new A(); }', 'return attend B, reçu A'],
];

foreach ($inheritanceErrors as $index => [$label, $source, $needle]) {
    try {
        $compilePhp('inheritance-error-' . $index, $source, 'c');
        T::ok(false, 'héritage : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'héritage : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

// ---------------------------------------------------------------------------
T::section('26. Compilateur PHP — interfaces, classes abstraites et parent::');

$interfaceSource = <<<'PHP'
<?php
interface Speaker {
    public function speak(): string;
}

class Dog implements Speaker {
    public function speak(): string {
        return "Woof";
    }
}

function talk(Speaker $speaker): string {
    return $speaker->speak();
}

echo talk(new Dog()), "\n";
PHP;

$interfaceC = $compilePhp('interface', $interfaceSource, 'c');
$interfaceCSource = (string) file_get_contents($interfaceC['cPath']);
T::ok(str_contains($interfaceCSource, 'CyObjectHeader'), 'interface : en-tête objet');
T::ok(str_contains($interfaceCSource, 'cy_ifaces'), 'interface : tableaux d’interfaces');
T::ok(str_contains($interfaceCSource, 'CyVTable_Speaker'), 'interface : vtable');
T::ok(str_contains($interfaceCSource, 'cy_iface_Dog_Speaker'), 'interface : implémentation');

if (!$hasGcc) {
    T::skip('gcc absent : exécution des interfaces non testée');
} else {
    $interfaceElf = $compilePhp('interface', $interfaceSource, 'elf');
    T::same(
        "Woof\n",
        shell_exec(escapeshellarg((string) $interfaceElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : interface et dispatch',
    );

    $abstractElf = $compilePhp('abstract-class', <<<'PHP'
<?php
abstract class Shape {
    public abstract function area(): float;

    public function label(): string {
        return "shape";
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

$square = new Square(3.0);
echo $square->area(), " ", $square->label(), "\n";
PHP, 'elf');
    T::same(
        "9 shape\n",
        shell_exec(escapeshellarg((string) $abstractElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : classe abstraite et méthode implémentée',
    );

    $parentElf = $compilePhp('parent-call', <<<'PHP'
<?php
class Animal {
    public string $name;

    public function __construct(string $name) {
        $this->name = $name;
    }

    public function speak(): string {
        return "...";
    }
}

class Dog extends Animal {
    public function __construct(string $name) {
        parent::__construct($name . "!");
    }

    public function speak(): string {
        return parent::speak() . " Woof";
    }
}

$dog = new Dog("Rex");
echo $dog->name, " ", $dog->speak(), "\n";
PHP, 'elf');
    T::same(
        "Rex! ... Woof\n",
        shell_exec(escapeshellarg((string) $parentElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : parent::__construct et parent::méthode',
    );

    $multiElf = $compilePhp('multi-interface', <<<'PHP'
<?php
interface A {
    public function a(): string;
}

interface B {
    public function b(): string;
}

class C implements A, B {
    public function a(): string { return "a"; }
    public function b(): string { return "b"; }
}

function combine(A $left, B $right): string {
    return $left->a() . $right->b();
}

echo combine(new C(), new C()), "\n";
PHP, 'elf');
    T::same(
        "ab\n",
        shell_exec(escapeshellarg((string) $multiElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : plusieurs interfaces',
    );

    $abstractInterfaceElf = $compilePhp('abstract-interface', <<<'PHP'
<?php
interface I {
    public function x(): int;
}

abstract class Base implements I {
    public function y(): int { return 1; }
}

class Impl extends Base {
    public function x(): int { return 2; }
}

$impl = new Impl();
echo $impl->x() + $impl->y(), "\n";
PHP, 'elf');
    T::same(
        "3\n",
        shell_exec(escapeshellarg((string) $abstractInterfaceElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : interface via classe abstraite',
    );

    $interfaceInheritanceElf = $compilePhp('interface-inheritance', <<<'PHP'
<?php
interface Speaker {
    public function speak(): string;
}

interface Named extends Speaker {
    public function name(): string;
}

class Dog implements Named {
    public function speak(): string { return "Woof"; }
    public function name(): string { return "Rex"; }
}

function talk(Speaker $speaker): string {
    return $speaker->speak();
}

function identify(Named $named): string {
    return $named->name();
}

$dog = new Dog();
echo talk($dog), " ", identify($dog), "\n";
PHP, 'elf');
    T::same(
        "Woof Rex\n",
        shell_exec(escapeshellarg((string) $interfaceInheritanceElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : interface héritée et dispatch parent',
    );

    $interfaceDiamondElf = $compilePhp('interface-diamond', <<<'PHP'
<?php
interface A { public function x(): string; }
interface B extends A { public function y(): string; }
interface C extends A { public function z(): string; }
interface D extends B, C { public function w(): string; }

class Impl implements D {
    public function x(): string { return "x"; }
    public function y(): string { return "y"; }
    public function z(): string { return "z"; }
    public function w(): string { return "w"; }
}

function viaA(A $a): string { return $a->x(); }
function viaB(B $b): string { return $b->y(); }
function viaC(C $c): string { return $c->z(); }
function viaD(D $d): string { return $d->w(); }

$impl = new Impl();
echo viaA($impl), viaB($impl), viaC($impl), viaD($impl), "\n";
PHP, 'elf');
    T::same(
        "xyzw\n",
        shell_exec(escapeshellarg((string) $interfaceDiamondElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : héritage multiple d’interfaces',
    );

    $covariantElf = $compilePhp('covariant-inheritance', <<<'PHP'
<?php
class Animal {
    public function name(): string { return "animal"; }
}

class Dog extends Animal {
    public function name(): string { return "dog"; }
}

class BaseFactory {
    public function make(): Animal { return new Animal(); }
}

class DogFactory extends BaseFactory {
    public function make(): Dog { return new Dog(); }
}

function label(BaseFactory $factory): string {
    return $factory->make()->name();
}

echo label(new DogFactory()), " ", label(new BaseFactory()), "\n";
PHP, 'elf');
    T::same(
        "dog animal\n",
        shell_exec(escapeshellarg((string) $covariantElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : retour covariant par héritage',
    );

    $covariantInterfaceElf = $compilePhp('covariant-interface', <<<'PHP'
<?php
interface Named {
    public function name(): string;
}

class Dog implements Named {
    public function name(): string { return "dog"; }
}

class Animal implements Named {
    public function name(): string { return "animal"; }
}

interface Factory {
    public function make(): Named;
}

class DogFactory implements Factory {
    public function make(): Dog { return new Dog(); }
}

function label(Factory $factory): string {
    return $factory->make()->name();
}

echo label(new DogFactory()), "\n";
PHP, 'elf');
    T::same(
        "dog\n",
        shell_exec(escapeshellarg((string) $covariantInterfaceElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : retour covariant via interface',
    );

    $covariantDiamondElf = $compilePhp('covariant-diamond', <<<'PHP'
<?php
class Animal {}
class Dog extends Animal {}

interface A { public function make(): Animal; }
interface B { public function make(): Dog; }
interface C extends B, A {}

class Impl implements C {
    public function make(): Dog { return new Dog(); }
}

echo "ok\n";
PHP, 'elf');
    T::same(
        "ok\n",
        shell_exec(escapeshellarg((string) $covariantDiamondElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : fusion de retours covariants',
    );
}

$oopErrors = [
    ['new abstrait', '<?php abstract class A { public abstract function f(): void; } new A();', 'classe abstraite interdite'],
    ['new interface', '<?php interface I { public function f(): void; } new I();', 'interface interdite'],
    ['abstract manquante', '<?php abstract class A { public abstract function f(): void; } class B extends A {} new B();', 'doit implémenter f'],
    ['interface manquante', '<?php interface I { public function f(): void; } class A implements I {} new A();', 'doit implémenter I::f'],
    ['interface signature', '<?php interface I { public function f(int $x): int; } class A implements I { public function f(string $x): int { return 1; } }', 'signature incompatible'],
    ['interface parent inconnu', '<?php interface A extends Missing {}', 'interface parente inconnue'],
    ['interface cycle', '<?php interface A extends B {} interface B extends A {}', 'cycle d’héritage d’interface'],
    ['interface hérite classe', '<?php class Base {} interface A extends Base {}', 'une interface ne peut pas hériter d’une classe'],
    ['interface conflit', '<?php interface A { public function f(int $x): int; } interface B { public function f(string $x): int; } interface C extends A, B {}', 'signature incompatible'],
    ['interface héritée manquante', '<?php interface A { public function f(): void; } interface B extends A { public function g(): void; } class Impl implements B { public function f(): void {} }', 'doit implémenter B::g'],
    ['classe implémentée comme interface', '<?php class Base {} class Impl implements Base {}', 'une classe ne peut pas implémenter une classe'],
    ['covariance élargie', '<?php class Animal {} class Dog extends Animal {} class Base { public function make(): Dog { return new Dog(); } } class Child extends Base { public function make(): Animal { return new Animal(); } }', 'signature incompatible'],
    ['covariance non liée', '<?php class Animal {} class Rock {} class Base { public function make(): Animal { return new Animal(); } } class Child extends Base { public function make(): Rock { return new Rock(); } }', 'signature incompatible'],
    ['covariance scalaire', '<?php class Base { public function value(): float { return 1.0; } } class Child extends Base { public function value(): int { return 1; } }', 'signature incompatible'],
    ['covariance paramètres', '<?php class Animal {} class Base { public function make(int $x): Animal { return new Animal(); } } class Child extends Base { public function make(string $x): Animal { return new Animal(); } }', 'signature incompatible'],
    ['fusion retours incompatibles', '<?php class Animal {} class Rock {} interface A { public function make(): Animal; } interface B { public function make(): Rock; } interface C extends A, B {}', 'signature incompatible pour la fusion'],
    ['interface retour élargi', '<?php class Animal {} class Dog extends Animal {} interface A { public function make(): Dog; } interface B extends A { public function make(): Animal; }', 'signature incompatible'],
    ['parent hors méthode', '<?php parent::foo();', 'parent:: utilisé hors d’une méthode'],
    ['parent absent', '<?php class A { function f(): void { parent::f(); } }', 'n’a pas de classe parente'],
    ['parent inconnu', '<?php class A { function f(): void {} } class B extends A { function f(): void { parent::missing(); } }', 'méthode parente inconnue'],
    ['parent abstrait', '<?php abstract class A { public abstract function f(): void; } class B extends A { function f(): void { parent::f(); } }', 'méthode abstraite parente'],
    ['propriété interface', '<?php interface I { public int $x; }', 'une interface ne peut contenir que des méthodes'],
    ['abstract avec corps', '<?php abstract class A { abstract function f(): void { } }', 'ne peut pas avoir de corps'],
    ['méthode sans corps', '<?php class A { function f(): void; }', 'méthode sans corps'],
    ['extends interface', '<?php interface I { function f(): void; } class A extends I {}', 'ne peut pas hériter d’une interface'],
];

foreach ($oopErrors as $index => [$label, $source, $needle]) {
    try {
        $compilePhp('oop-error-' . $index, $source, 'c');
        T::ok(false, 'OOP : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'OOP : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

// ---------------------------------------------------------------------------
T::section('27. Compilateur PHP — membres static');

$staticSource = <<<'PHP'
<?php
class Counter {
    public static int $count;
    public static string $label;

    public static function increment(): int {
        self::$count = self::$count + 1;
        return self::$count;
    }

    public static function label(): string {
        return self::$label;
    }
}

Counter::$label = "n";
echo Counter::increment(), "\n";
echo Counter::increment(), "\n";
Counter::$count = 10;
echo Counter::$count, "\n";
echo Counter::label(), "\n";
PHP;

$staticC = $compilePhp('static-basic', $staticSource, 'c');
$staticCSource = (string) file_get_contents($staticC['cPath']);
T::ok(str_contains($staticCSource, 'cy_static_Counter_count'), 'static : globale de propriété');
T::ok(str_contains($staticCSource, 'cy_static_Counter_label'), 'static : globale chaîne');
T::ok(str_contains($staticCSource, 'fn_Counter__increment'), 'static : fonction de méthode');
T::ok(str_contains($staticCSource, 'cy_add_root_slot'), 'static : racine GC enregistrée');

if (!$hasGcc) {
    T::skip('gcc absent : exécution des membres static non testée');
} else {
    $staticElf = $compilePhp('static-basic', $staticSource, 'elf');
    T::same(
        "1\n2\n10\nn\n",
        shell_exec(escapeshellarg((string) $staticElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : propriétés et méthodes static',
    );

    $staticInheritElf = $compilePhp('static-inherit', <<<'PHP'
<?php
class Base {
    public static int $count;
    public static string $name;

    public static function bump(): int {
        self::$count = self::$count + 1;
        return self::$count;
    }

    public static function who(): string {
        return "base";
    }
}

class Child extends Base {
    public static function who(): string {
        return "child";
    }
}

echo Child::bump(), "\n";
Child::$count = Child::$count + 1;
echo Base::$count, "\n";
echo Base::who(), " ", Child::who(), "\n";
Child::$name = "partage";
echo Base::$name, "\n";
PHP, 'elf');
    T::same(
        "1\n2\nbase child\npartage\n",
        shell_exec(escapeshellarg((string) $staticInheritElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : static partagé et override',
    );

    $staticObjectElf = $compilePhp('static-object', <<<'PHP'
<?php
class Packet {
    public string $value;

    public function __construct(string $value) {
        $this->value = $value;
    }

    public function get(): string {
        return $this->value;
    }
}

class Registry {
    public static Packet $box;
    public static string $label;

    public static function install(Packet $box): void {
        self::$box = $box;
        self::$label = $box->get();
    }
}

Registry::install(new Packet("native"));
$i = 0;

while ($i < 2000) {
    $tmp = strval($i);
    $i = $i + 1;
}

echo Registry::$box->get(), " ", Registry::$label, "\n";
PHP, 'elf');
    T::same(
        "native native\n",
        shell_exec(escapeshellarg((string) $staticObjectElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : propriété statique objet et GC',
    );

    $staticClosureElf = $compilePhp('static-closure', <<<'PHP'
<?php
class Hooks {
    public static callable(int):int $double;

    public static function install(callable(int):int $hook): void {
        self::$double = $hook;
    }

    public static function run(int $value): int {
        $fn = self::$double;
        return $fn($value);
    }
}

Hooks::install(fn (int $x): int => $x * 2);
echo Hooks::run(21), "\n";
PHP, 'elf');
    T::same(
        "42\n",
        shell_exec(escapeshellarg((string) $staticClosureElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : propriété statique callable',
    );

    $staticThrowElf = $compilePhp('static-throw', <<<'PHP'
<?php
class Guard {
    public static function check(int $value): int {
        if ($value < 0) {
            throw "negatif";
        }

        return $value;
    }
}

try {
    echo Guard::check(2), "\n";
    Guard::check(-1);
    echo "jamais\n";
} catch (string $error) {
    echo "erreur: ", $error, "\n";
}
PHP, 'elf');
    T::same(
        "2\nerreur: negatif\n",
        shell_exec(escapeshellarg((string) $staticThrowElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : exception depuis une méthode statique',
    );
}

$staticErrors = [
    ['this statique', '<?php class A { public static function f(): void { echo $this; } }', '$this n’est pas disponible dans une méthode statique'],
    ['non statique statique', '<?php class A { public function f(): void {} } A::f();', 'méthode non statique appelée statiquement'],
    ['statique via objet', '<?php class A { public static function f(): void {} } $a = new A(); $a->f();', 'méthode statique appelée via ->'],
    ['static abstraite', '<?php abstract class A { public abstract static function f(): void; }', 'méthode static abstraite non supportée'],
    ['static interface', '<?php interface I { public static function f(): void; }', 'membre static interdit dans une interface'],
    ['static::', '<?php class A { public static function f(): void { static::f(); } }', 'static:: non supporté'],
    ['parent static', '<?php class A { public static function f(): void {} } class B extends A { public static function g(): void { parent::f(); } }', 'parent:: non supporté dans une méthode statique'],
    ['propriété statique inconnue', '<?php class A {} A::$missing = 1;', 'propriété statique inconnue A::$missing'],
    ['méthode statique inconnue', '<?php class A {} A::missing();', 'méthode inconnue A::missing'],
    ['accès statique interface', '<?php interface I {} I::$x = 1;', 'accès statique interdit sur l’interface I'],
    ['static incompatible', '<?php class A { public static function f(): void {} } class B extends A { public function f(): void {} }', 'static incompatible'],
    ['collision statique', '<?php class A { public static int $x; } class B extends A { public int $x; }', 'propriété déjà définie dans l’héritage'],
    ['constante de classe', '<?php class A {} echo A::VALUE;', 'constante de classe non supportée'],
    ['self hors classe', '<?php self::f();', 'self:: utilisé hors d’une classe'],
];

foreach ($staticErrors as $index => [$label, $source, $needle]) {
    try {
        $compilePhp('static-error-' . $index, $source, 'c');
        T::ok(false, 'static : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'static : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

// ---------------------------------------------------------------------------
T::section('28. Compilateur PHP — tableaux typés en signatures');

$arraySignatureSource = <<<'PHP'
<?php
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
function build(int $size): array {
    $values = [];
    $i = 0;

    while ($i < $size) {
        $values[] = $i * 10;
        $i = $i + 1;
    }

    return $values;
}

/**
 * @param array<int> $values
 */
function mutate(array $values): void {
    $values[] = 99;
}

$values = build(4);
echo total($values), " ", count($values), "\n";
mutate($values);
echo count($values), "\n";

$table = ["a" => 1, "b" => 2];
echo lookup($table, "b"), "\n";
PHP;

$arraySignatureC = $compilePhp('array-signature', $arraySignatureSource, 'c');
$arraySignatureCSource = (string) file_get_contents($arraySignatureC['cPath']);
T::ok(
    str_contains($arraySignatureCSource, 'static cy_int fn_total(CyArray *)'),
    'tableaux : prototype de paramètre indexé',
);
T::ok(
    str_contains($arraySignatureCSource, 'static cy_int fn_lookup(CyMap *, const char *)'),
    'tableaux : prototype de table associative',
);
T::ok(
    str_contains($arraySignatureCSource, 'cy_array_copy('),
    'tableaux : copie au passage d’argument',
);

if (!$hasGcc) {
    T::skip('gcc absent : exécution des tableaux en signatures non testée');
} else {
    $arraySignatureElf = $compilePhp('array-signature', $arraySignatureSource, 'elf');
    T::same(
        "60 4\n4\n2\n",
        shell_exec(escapeshellarg((string) $arraySignatureElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : paramètres et retours tableaux',
    );

    $arrayObjectElf = $compilePhp('array-object-signature', <<<'PHP'
<?php
interface BagInterface {
    /** @return array<int> */
    public function values(): array;
    public function size(): int;
}

class Bag implements BagInterface {
    /** @var array<int> */
    public array $items;

    /** @param array<int> $items */
    public function __construct(array $items) {
        $this->items = $items;
    }

    /** @param array<int> $items */
    public function replace(array $items): void {
        $this->items = $items;
    }

    /** @return array<int> */
    public function values(): array {
        return $this->items;
    }

    public function size(): int {
        return count($this->items);
    }
}

$bag = new Bag([1, 2]);
$bag->replace([1, 2, 3]);
$copy = $bag->values();
$copy[] = 100;
echo $bag->size(), " ", count($copy), "\n";
PHP, 'elf');
    T::same(
        "3 4\n",
        shell_exec(escapeshellarg((string) $arrayObjectElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : tableaux en méthodes, interfaces et propriétés',
    );

    $arrayAliasElf = $compilePhp('array-alias-signature', <<<'PHP'
<?php
class Store {
    /** @var array<int> */
    public static array $items;
}

Store::$items = [1];

/** @return array<int> */
function take(): array {
    return Store::$items;
}

/** @return array<string,int> */
function emptyTable(): array {
    return [];
}

/** @param array<string,int> $table */
function size(array $table): int {
    return count($table);
}

$got = take();
$got[] = 2;
echo count(Store::$items), " ", count($got), " ", count(emptyTable()), " ", size([]), "\n";
PHP, 'elf');
    T::same(
        "1 2 0 0\n",
        shell_exec(escapeshellarg((string) $arrayAliasElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : copie des tableaux et littéraux vides typés',
    );
}

$arraySignatureErrors = [
    ['tableau sans annotation', '<?php function f(array $values): void {}', 'type de tableau incomplet'],
    ['annotation élément inconnu', '<?php /** @param array<Dog> $values */ function f(array $values): void {}', 'type d’élément de tableau non supporté'],
    ['liste vers table', '<?php /** @param array<string,int> $table */ function f(array $table): void {} f([1, 2]);', 'attend array<string,int>, reçu array<int>'],
    ['éléments incompatibles', '<?php /** @param array<int> $values */ function f(array $values): void {} $floats = [1.5, 2.5]; f($floats);', 'attend array<int>, reçu array<float>'],
    ['retour incompatible', '<?php /** @return array<int> */ function f(): array { return ["a" => 1]; }', 'return attend array<int>, reçu array<string,int>'],
    ['affectation incompatible', '<?php /** @param array<string,int> $table */ function f(array $table): void { $table = [1, 2]; }', 'valeur array<int> refusée'],
];

foreach ($arraySignatureErrors as $index => [$label, $source, $needle]) {
    try {
        $compilePhp('array-signature-error-' . $index, $source, 'c');
        T::ok(false, 'tableaux : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'tableaux : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

// ---------------------------------------------------------------------------
T::section('29. Compilateur PHP — capture par référence');

$byRefSource = <<<'PHP'
<?php
$count = 0;
$inc = function () use (&$count): void {
    $count = $count + 1;
};

$inc();
$inc();
echo $count, "\n";
$count = 10;
$inc();
echo $count, "\n";
PHP;

$byRefC = $compilePhp('capture-by-ref', $byRefSource, 'c');
$byRefCSource = (string) file_get_contents($byRefC['cPath']);
T::ok(str_contains($byRefCSource, 'vc_count'), 'référence : cellule de variable');
T::ok(str_contains($byRefCSource, 'cap_count'), 'référence : champ de closure');
T::ok(str_contains($byRefCSource, 'cy_object_new'), 'référence : cellule GC');

if (!$hasGcc) {
    T::skip('gcc absent : exécution des captures par référence non testée');
} else {
    $byRefElf = $compilePhp('capture-by-ref', $byRefSource, 'elf');
    T::same(
        "2\n11\n",
        shell_exec(escapeshellarg((string) $byRefElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : capture scalaire par référence',
    );

    $byRefEscapeElf = $compilePhp('capture-by-ref-escape', <<<'PHP'
<?php
function makeCounter(): callable():int {
    $n = 0;
    return function () use (&$n): int {
        $n = $n + 1;
        return $n;
    };
}

$counter = makeCounter();
echo $counter(), " ", $counter(), " ", $counter(), "\n";
$i = 0;

while ($i < 2000) {
    $tmp = strval($i);
    $i = $i + 1;
}

echo $counter(), "\n";
PHP, 'elf');
    T::same(
        "1 2 3\n4\n",
        shell_exec(escapeshellarg((string) $byRefEscapeElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : cellule capturée survivant à la GC',
    );

    $byRefContainersElf = $compilePhp('capture-by-ref-containers', <<<'PHP'
<?php
$list = [1];
$push = function () use (&$list): void {
    $list[] = 2;
    $list[] = 3;
};
$push();
echo count($list), "\n";

class Packet {
    public int $value;
    public function __construct(int $value) { $this->value = $value; }
}

$packet = new Packet(1);
$replace = function () use (&$packet): void {
    $packet = new Packet(7);
};
$replace();
echo $packet->value, "\n";

$fn = fn (int $x): int => $x + 1;
$swap = function () use (&$fn): void {
    $fn = fn (int $x): int => $x * 2;
};
$swap();
echo $fn(3), "\n";
PHP, 'elf');
    T::same(
        "3\n7\n6\n",
        shell_exec(escapeshellarg((string) $byRefContainersElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : références vers tableau, objet et closure',
    );

    $byRefNestedElf = $compilePhp('capture-by-ref-nested', <<<'PHP'
<?php
$count = 0;
$outer = function () use (&$count): void {
    $inner = function () use (&$count): void {
        $count = $count + 1;
    };
    $inner();
    $inner();
};
$outer();
echo $count, "\n";

$run = function (): int {
    $n = 0;
    $inner = function () use (&$n): void {
        $n = $n + 1;
    };
    $inner();
    $inner();
    return $n;
};
echo $run(), "\n";
PHP, 'elf');
    T::same(
        "2\n2\n",
        shell_exec(escapeshellarg((string) $byRefNestedElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : références dans closures imbriquées',
    );

    $byRefMethodElf = $compilePhp('capture-by-ref-method', <<<'PHP'
<?php
class Counter {
    public function run(): int {
        $n = 0;
        $bump = function () use (&$n): void {
            $n = $n + 2;
        };
        $bump();
        $bump();
        return $n;
    }

    public static function staticRun(int $start): int {
        $value = $start;
        $add = function () use (&$value): void {
            $value = $value + 3;
        };
        $add();
        return $value;
    }
}

echo (new Counter())->run(), " ", Counter::staticRun(1), "\n";
PHP, 'elf');
    T::same(
        "4 4\n",
        shell_exec(escapeshellarg((string) $byRefMethodElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : référence dans méthodes instance et statique',
    );
}

$byRefErrors = [
    ['this', '<?php class A { public function f(): void { $c = function () use (&$this): void {}; } }', 'capture de $this non supportée'],
    ['variable inconnue', '<?php $c = function () use (&$missing): void {};', 'variable capturée non définie'],
    ['capture dupliquée', '<?php $x = 1; $c = function () use ($x, &$x): void {};', 'capture dupliquée'],
    ['variable non affectée', '<?php if (true) { $x = 1; } $c = function () use (&$x): void {};', 'capturée mais peut ne pas être affectée'],
];

foreach ($byRefErrors as $index => [$label, $source, $needle]) {
    try {
        $compilePhp('capture-by-ref-error-' . $index, $source, 'c');
        T::ok(false, 'référence : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'référence : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

// ---------------------------------------------------------------------------
T::section('30. Modules natifs — SQLite');

$sqliteSource = <<<'PHP'
<?php
$db = sqlite_open(":memory:");

if ($db === 0) {
    throw "ouverture impossible";
}

sqlite_exec($db, "CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT, price REAL)");
sqlite_exec($db, "INSERT INTO items (name, price) VALUES ('alpha', 1.5)");
sqlite_exec($db, "INSERT INTO items (name, price) VALUES ('beta', 2.5)");

echo sqlite_query_int($db, "SELECT count(*) FROM items"), "\n";
echo sqlite_query_string($db, "SELECT name FROM items WHERE id = 1"), "\n";
echo sqlite_query_float($db, "SELECT price FROM items WHERE id = 2"), "\n";
echo sqlite_query_bool($db, "SELECT count(*) > 1 FROM items"), "\n";
echo sqlite_last_id($db), "\n";

sqlite_exec($db, "UPDATE items SET price = 3.0 WHERE id = 1");
echo sqlite_changes($db), "\n";

if (!sqlite_exec($db, "SELECT * FROM missing")) {
    echo "erreur: ", sqlite_error($db), "\n";
}

if (sqlite_close($db)) {
    echo "ferme\n";
} else {
    echo "echec\n";
}
PHP;

$sqliteC = $compilePhp('sqlite-basic', $sqliteSource, 'c');
$sqliteCSource = (string) file_get_contents($sqliteC['cPath']);
T::ok(str_contains($sqliteCSource, '#include <sqlite3.h>'), 'SQLite : include natif');
T::ok(str_contains($sqliteCSource, 'sqlite3_open_v2'), 'SQLite : shim C');
T::ok(str_contains($sqliteCSource, 'fn_sqlite_query_int'), 'SQLite : fonction native');

exec('pkg-config --exists sqlite3 2>/dev/null', $sqliteLines, $sqliteStatus);
$hasSqlite = $sqliteStatus === 0;

if (!$hasGcc || !$hasSqlite) {
    T::skip('gcc ou sqlite3 de développement absent : exécution SQLite non testée');
} else {
    $sqliteElf = $compilePhp('sqlite-basic', $sqliteSource, 'elf');
    T::same(
        "2\nalpha\n2.5\n1\n2\n1\nerreur: no such table: missing\nferme\n",
        shell_exec(escapeshellarg((string) $sqliteElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : module natif SQLite',
    );

    $sqliteOopElf = $compilePhp('sqlite-oop', <<<'PHP'
<?php
class Database {
    public int $handle;

    public function __construct(string $path) {
        $this->handle = sqlite_open($path);
    }

    public function setup(): void {
        sqlite_exec($this->handle, "CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)");
    }

    public function add(string $name): int {
        $sql = "INSERT INTO t (name) VALUES ('" . $name . "')";
        sqlite_exec($this->handle, $sql);
        return sqlite_last_id($this->handle);
    }

    public function count(): int {
        return sqlite_query_int($this->handle, "SELECT count(*) FROM t");
    }
}

$db = new Database(":memory:");
$db->setup();
$db->add("a");
$db->add("b");

$reader = function () use ($db): int {
    return $db->count();
};

echo $reader(), "\n";
PHP, 'elf');
    T::same(
        "2\n",
        shell_exec(escapeshellarg((string) $sqliteOopElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : SQLite via objet et closure',
    );
}

try {
    $compilePhp('sqlite-collision', '<?php function sqlite_open(string $path): int { return 1; } echo sqlite_open("x");', 'c');
    T::ok(false, 'SQLite : conflit de fonction native');
} catch (Cyonima\Compiler\CompilerException $exception) {
    T::ok(
        str_contains($exception->getMessage(), 'conflit de fonction native'),
        'SQLite : conflit de fonction native',
        $exception->getMessage(),
    );
}

try {
    $unknownPath = $phpRoot . '/src/sqlite-unknown-module.php';
    file_put_contents($unknownPath, '<?php echo 1;');
    (new PhpCompiler($phpRoot, ['missing']))->compile($unknownPath, null, 'c');
    T::ok(false, 'modules natifs : module inconnu');
} catch (Cyonima\Compiler\CompilerException $exception) {
    T::ok(
        str_contains($exception->getMessage(), 'module natif inconnu'),
        'modules natifs : module inconnu',
        $exception->getMessage(),
    );
}

// ---------------------------------------------------------------------------
T::section('31. Modules natifs — curl et ssh2');

$curlSource = <<<'PHP'
<?php
$handle = curl_init("__URL__");

if ($handle === 0) {
    throw "initialisation curl impossible";
}

curl_set_header($handle, "X-Cyonima: 1");

if (!curl_perform($handle)) {
    echo "erreur: ", curl_error($handle), "\n";
} else {
    echo curl_body($handle);
}

curl_close($handle);
PHP;

$curlC = $compilePhp('curl-module', $curlSource, 'c');
$curlCSource = (string) file_get_contents($curlC['cPath']);
T::ok(str_contains($curlCSource, '#include <curl/curl.h>'), 'curl : include natif');
T::ok(str_contains($curlCSource, 'fn_curl_perform'), 'curl : shim C');
T::ok(str_contains((string) $curlC['linkFlags'], 'lcurl'), 'curl : option de liaison');

$sshSource = <<<'PHP'
<?php
$handle = ssh2_connect("127.0.0.1", 1);

if ($handle === 0) {
    echo "connexion refusee: ", ssh2_error(0), "\n";
} else {
    echo "inattendu\n";
    ssh2_disconnect($handle);
}
PHP;

$sshC = $compilePhp('ssh2-module', $sshSource, 'c');
$sshCSource = (string) file_get_contents($sshC['cPath']);
T::ok(str_contains($sshCSource, '#include <libssh2.h>'), 'ssh2 : include natif');
T::ok(str_contains($sshCSource, 'fn_ssh2_connect'), 'ssh2 : shim C');
T::ok(str_contains((string) $sshC['linkFlags'], 'lssh2'), 'ssh2 : option de liaison');

exec('pkg-config --exists libcurl 2>/dev/null', $curlLines, $curlStatus);
exec('pkg-config --exists libssh2 2>/dev/null', $sshLines, $sshStatus);
$hasCurl = $curlStatus === 0;
$hasSsh2 = $sshStatus === 0;

if (!$hasGcc || !$hasCurl) {
    T::skip('gcc ou libcurl de développement absent : exécution curl non testée');
} else {
    $payload = $phpRoot . '/src/curl-payload.txt';
    file_put_contents($payload, "bonjour curl\n");
    $curlProgram = str_replace('__URL__', 'file://' . $payload, $curlSource);
    $curlElf = $compilePhp('curl-runtime', $curlProgram, 'elf');
    T::same(
        "bonjour curl\n",
        shell_exec(escapeshellarg((string) $curlElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : module curl sur fichier local',
    );
}

if (!$hasGcc || !$hasSsh2) {
    T::skip('gcc ou libssh2 de développement absent : exécution ssh2 non testée');
} else {
    $sshElf = $compilePhp('ssh-runtime', $sshSource, 'elf');
    $sshOutput = (string) shell_exec(
        escapeshellarg((string) $sshElf['binaryPath']) . ' 2>&1',
    );
    T::ok(
        str_contains($sshOutput, 'connexion refusee:') && str_contains($sshOutput, 'impossible'),
        'compilateur PHP : module ssh2 et erreur de connexion',
        $sshOutput,
    );
}

// ---------------------------------------------------------------------------
T::section('32. Packaging natif — AppImage et Flatpak');

$packageRoot = $phpRoot . '/native-package-project';
$packageSource = $packageRoot . '/src/main.php';
$packageAppId = 'com.example.cyonimanative';
mkdir($packageRoot . '/.cyonima', 0775, true);
mkdir($packageRoot . '/src', 0775, true);
mkdir($packageRoot . '/assets', 0775, true);
file_put_contents($packageRoot . '/.cyonima/project.json', json_encode([
    'name' => 'Native Package',
    'appId' => $packageAppId,
    'version' => '0.1.0',
    'entry' => 'src/main.php',
], JSON_PRETTY_PRINT));
file_put_contents($packageSource, "<?php\necho \"native package\\n\";\n");

$repositoryRoot = dirname(__DIR__);
$hasAppImageTool = is_file($repositoryRoot . '/.tools/appimagetool')
    || is_file($repositoryRoot . '/tools/appimagetool')
    || trim((string) shell_exec('command -v appimagetool 2>/dev/null')) !== '';

if (!$hasGcc || !$hasAppImageTool) {
    T::skip('gcc ou appimagetool absent : packaging AppImage non testé');
} else {
    $appImageResult = (new PhpCompiler($packageRoot))->compile(
        $packageSource,
        null,
        'elf',
        'appimage',
    );
    T::ok(
        isset($appImageResult['packagePath']) && is_file((string) $appImageResult['packagePath']),
        'packaging : AppImage native générée',
    );
    T::same(
        "native package\n",
        shell_exec(
            'APPIMAGE_EXTRACT_AND_RUN=1 '
            . escapeshellarg((string) $appImageResult['packagePath'])
            . ' 2>&1',
        ),
        'packaging : exécution de l’AppImage native',
    );
}

if (!$hasGcc || !$hasAppImageTool || !$hasSqlite) {
    T::skip('SQLite absent : AppImage avec module natif non testée');
} else {
    file_put_contents($packageSource, <<<'PHP'
<?php
$db = sqlite_open(":memory:");
sqlite_exec($db, "CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)");
sqlite_exec($db, "INSERT INTO t (name) VALUES ('packaged')");
echo sqlite_query_string($db, "SELECT name FROM t WHERE id = 1"), "\n";
sqlite_close($db);
PHP);
    $sqliteAppImage = (new PhpCompiler($packageRoot))->compile(
        $packageSource,
        null,
        'elf',
        'appimage',
    );
    T::same(
        "packaged\n",
        shell_exec(
            'APPIMAGE_EXTRACT_AND_RUN=1 '
            . escapeshellarg((string) $sqliteAppImage['packagePath'])
            . ' 2>&1',
        ),
        'packaging : bibliothèque SQLite embarquée',
    );
}

$hasFlatpakBuilder = trim((string) shell_exec('command -v flatpak-builder 2>/dev/null')) !== '';
$hasGnomeSdk = trim((string) shell_exec(
    'flatpak info org.gnome.Sdk//49 >/dev/null 2>&1; echo $?',
)) === '0';
$runFlatpakTest = getenv('CYONIMA_TEST_FLATPAK') === '1';

if (!$runFlatpakTest || !$hasGcc || !$hasFlatpakBuilder || !$hasGnomeSdk) {
    T::skip('Flatpak natif non testé (CYONIMA_TEST_FLATPAK=1, SDK GNOME requis)');
} else {
    file_put_contents($packageSource, "<?php\necho \"native flatpak\\n\";\n");
    $flatpakResult = (new PhpCompiler($packageRoot))->compile(
        $packageSource,
        null,
        'elf',
        'flatpak',
    );
    $bundle = (string) ($flatpakResult['packagePath'] ?? '');
    T::ok($bundle !== '' && is_file($bundle), 'packaging : Flatpak natif généré');

    shell_exec(
        'flatpak install --user --noninteractive ' . escapeshellarg($bundle) . ' 2>&1',
    );
    $flatpakOutput = shell_exec(
        'flatpak run ' . escapeshellarg($packageAppId) . ' 2>&1',
    );
    shell_exec(
        'flatpak uninstall --user -y ' . escapeshellarg($packageAppId) . ' 2>&1',
    );
    T::same("native flatpak\n", $flatpakOutput, 'packaging : exécution du Flatpak natif');
}

// ---------------------------------------------------------------------------
T::section('33. Collections d’objets — array<Classe> et array<string,Classe>');

$objectCollectionSource = <<<'PHP'
<?php
class User {
    public string $name;

    public function __construct(string $name) {
        $this->name = $name;
    }

    public function label(): string {
        return $this->name;
    }
}

interface Named {
    public function name(): string;
}

class Person implements Named {
    public string $value;

    public function __construct(string $value) {
        $this->value = $value;
    }

    public function name(): string {
        return $this->value;
    }
}

/**
 * @return array<User>
 */
function makeUsers(): array {
    return [new User("alice"), new User("bob")];
}

/**
 * @param array<User> $users
 */
function describe(array $users): string {
    $result = "";
    $i = 0;

    while ($i < count($users)) {
        $result = $result . $users[$i]->label() . ",";
        $i = $i + 1;
    }

    return $result;
}

/**
 * @return array<string,User>
 */
function makeTable(): array {
    $table = [];
    $table["a"] = new User("alice");
    $table["b"] = new User("bob");
    return $table;
}

/**
 * @param array<string,User> $table
 */
function describeTable(array $table): string {
    $result = "";

    foreach ($table as $key => $user) {
        $result = $result . $key . "=" . $user->label() . ";";
    }

    return $result;
}

/**
 * @param array<Named> $items
 */
function joinNames(array $items): string {
    $result = "";

    foreach ($items as $item) {
        $result = $result . $item->name() . ",";
    }

    return $result;
}

class Bag {
    /** @var array<User> */
    public array $users;

    /** @param array<User> $users */
    public function __construct(array $users) {
        $this->users = $users;
    }

    public function size(): int {
        return count($this->users);
    }
}

class Registry {
    /** @var array<User> */
    public static array $users;
}

$users = makeUsers();
$users[] = new User("carol");
echo count($users), " ", describe($users), "\n";

$table = makeTable();
echo count($table), " ", describeTable($table), " ", $table["b"]->label(), "\n";

echo joinNames([new Person("x"), new Person("y")]), "\n";

$bag = new Bag($users);
echo $bag->size(), "\n";

Registry::$users = $users;
$copy = Registry::$users;
$copy[] = new User("extra");
echo count(Registry::$users), " ", count($copy), "\n";

$first = $users[0];
$closure = function () use ($first): string {
    return $first->label();
};
echo $closure(), "\n";
PHP;

$objectCollectionC = $compilePhp('object-collections', $objectCollectionSource, 'c');
$objectCollectionCSource = (string) file_get_contents($objectCollectionC['cPath']);
T::ok(str_contains($objectCollectionCSource, 'cy_array_push_object'), 'collections : ajout d’objet');
T::ok(str_contains($objectCollectionCSource, 'cy_array_get_object'), 'collections : lecture d’objet');
T::ok(str_contains($objectCollectionCSource, 'cy_map_set_string_object'), 'collections : table d’objets');
T::ok(str_contains($objectCollectionCSource, 'cy_map_value_object'), 'collections : foreach d’objets');

if (!$hasGcc) {
    T::skip('gcc absent : exécution des collections d’objets non testée');
} else {
    $objectCollectionElf = $compilePhp('object-collections', $objectCollectionSource, 'elf');
    T::same(
        "3 alice,bob,carol,\n2 a=alice;b=bob; bob\nx,y,\n3\n3 4\nalice\n",
        shell_exec(escapeshellarg((string) $objectCollectionElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : listes, tables, propriétés statiques et closures d’objets',
    );

    $objectCollectionGcElf = $compilePhp('object-collections-gc', <<<'PHP'
<?php
class User {
    public string $name;

    public function __construct(string $name) {
        $this->name = $name;
    }

    public function label(): string {
        return $this->name;
    }
}

/**
 * @return array<User>
 */
function makeUsers(int $count): array {
    $users = [];
    $i = 0;

    while ($i < $count) {
        $users[] = new User("u" . strval($i));
        $i = $i + 1;
    }

    return $users;
}

$users = makeUsers(8);
$i = 0;

while ($i < 2000) {
    $tmp = makeUsers(3);
    $i = $i + 1;
}

echo count($users), " ", $users[7]->label(), "\n";
PHP, 'elf');
    T::same(
        "8 u7\n",
        shell_exec(escapeshellarg((string) $objectCollectionGcElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : collections d’objets et GC',
    );
}

$objectCollectionErrors = [
    ['élément inconnu', '<?php /** @return array<Missing> */ function f(): array { return []; }', 'type d’élément de tableau non supporté'],
    ['élément callable', '<?php /** @return array<callable> */ function f(): array { return []; }', 'type d’élément de tableau non supporté'],
    ['tableau hétérogène', '<?php class A {} class B {} $items = [new A(), new B()];', 'tableau hétérogène'],
    ['retour incompatible', '<?php class User {} /** @return array<User> */ function f(): array { return [1]; }', 'return attend array<User>, reçu array<int>'],
    ['tableau imbriqué', '<?php /** @return array<array<int>> */ function f(): array { return []; }', 'type de tableau incomplet'],
    ['ajout incompatible', '<?php class User {} class Other {} /** @param array<User> $items */ function add(array $items): void { $items[] = new Other(); }', 'attend User, reçu Other'],
];

foreach ($objectCollectionErrors as $index => [$label, $source, $needle]) {
    try {
        $compilePhp('object-collection-error-' . $index, $source, 'c');
        T::ok(false, 'collections : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'collections : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

// ---------------------------------------------------------------------------
T::section('34. Types nullables — ?string, ?Classe, ?Interface, ?callable');

$nullableSource = <<<'PHP'
<?php
class User {
    public string $name;

    public function __construct(string $name) {
        $this->name = $name;
    }

    public function label(): string {
        return $this->name;
    }
}

function greet(?string $name): string {
    if ($name === null) {
        return "nobody";
    }

    return "hello " . $name;
}

function find(bool $present): ?User {
    if (!$present) {
        return null;
    }

    return new User("alice");
}

class Holder {
    public ?User $user;
    public ?string $note;

    public function __construct(?User $user, ?string $note) {
        $this->user = $user;
        $this->note = $note;
    }

    public function label(): string {
        return $this->user->label();
    }
}

class Payload {
    public string $value;

    public function __construct(string $value) {
        $this->value = $value;
    }
}

class MaybePayload {
    public ?Payload $payload;

    public function __construct(?Payload $payload) {
        $this->payload = $payload;
    }
}

$found = find(true);
$missing = find(false);

if ($found !== null) {
    echo $found->label(), "\n";
}

if ($missing === null) {
    echo "missing\n";
}

echo greet("bob"), " ", greet(null), "\n";

$full = new Holder($found, "ok");
$empty = new Holder($missing, null);
echo $full->label(), " ", $full->note, "\n";
echo $empty->note, "|\n";

try {
    echo $empty->label(), "\n";
} catch (string $error) {
    echo "catch: ", $error, "\n";
}

$payload = new MaybePayload(new Payload("value"));
echo $payload->payload->value, "\n";

$noPayload = new MaybePayload(null);

try {
    echo $noPayload->payload->value, "\n";
} catch (string $error) {
    echo "prop: ", $error, "\n";
}
PHP;

$nullableC = $compilePhp('nullable-types', $nullableSource, 'c');
$nullableCSource = (string) file_get_contents($nullableC['cPath']);
T::ok(str_contains($nullableCSource, 'appel de méthode sur null'), 'nullable : garde de méthode');
T::ok(str_contains($nullableCSource, 'accès à une propriété sur null'), 'nullable : garde de propriété');
T::ok(str_contains($nullableCSource, ' == NULL'), 'nullable : comparaisons null');

if (!$hasGcc) {
    T::skip('gcc absent : exécution des types nullables non testée');
} else {
    $nullableElf = $compilePhp('nullable-types', $nullableSource, 'elf');
    T::same(
        "alice\nmissing\nhello bob nobody\nalice ok\n|\ncatch: appel de méthode sur null\nvalue\nprop: accès à une propriété sur null\n",
        shell_exec(escapeshellarg((string) $nullableElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : chaînes, objets et propriétés nullables',
    );

    $nullableCallableElf = $compilePhp('nullable-callable', <<<'PHP'
<?php
class Runner {
    public ?callable(int):int $hook;

    public function __construct(?callable(int):int $hook) {
        $this->hook = $hook;
    }

    public function run(int $value): int {
        $hook = $this->hook;
        return $hook($value);
    }
}

$double = fn (int $x): int => $x * 2;
$with = new Runner($double);
$without = new Runner(null);
echo $with->run(21), "\n";

if ($without->hook === null) {
    echo "no hook\n";
}

try {
    echo $without->run(1), "\n";
} catch (string $error) {
    echo "catch: ", $error, "\n";
}
PHP, 'elf');
    T::same(
        "42\nno hook\ncatch: appel d’une closure null\n",
        shell_exec(escapeshellarg((string) $nullableCallableElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : callable nullable',
    );

    $nullableCovarianceElf = $compilePhp('nullable-covariance', <<<'PHP'
<?php
class Animal {
    public function name(): string { return "animal"; }
}

class Dog extends Animal {
    public function name(): string { return "dog"; }
}

class Base {
    public function find(): ?Animal { return null; }
}

class Child extends Base {
    public function find(): ?Dog { return new Dog(); }
}

function label(Base $base): string {
    $value = $base->find();

    if ($value === null) {
        return "none";
    }

    return $value->name();
}

echo label(new Child()), " ", label(new Base()), "\n";
PHP, 'elf');
    T::same(
        "dog none\n",
        shell_exec(escapeshellarg((string) $nullableCovarianceElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : retour nullable covariant',
    );

    $nullableGcElf = $compilePhp('nullable-gc', <<<'PHP'
<?php
class User {
    public string $name;
    public function __construct(string $name) { $this->name = $name; }
    public function label(): string { return $this->name; }
}

class Registry {
    public static ?User $current;
    public static ?string $label;
}

Registry::$current = new User("alice");
Registry::$label = "hello";
$i = 0;

while ($i < 2000) {
    $tmp = strval($i);
    $i = $i + 1;
}

if (Registry::$current !== null) {
    echo Registry::$current->label(), " ", Registry::$label, "\n";
}

$value = Registry::$current;
$show = function () use (&$value): void {
    if ($value === null) {
        echo "null\n";
    } else {
        echo $value->label(), "\n";
    }
};

$show();
$value = null;
$show();
PHP, 'elf');
    T::same(
        "alice hello\nalice\nnull\n",
        shell_exec(escapeshellarg((string) $nullableGcElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : nullables, propriétés statiques, GC et références',
    );
}

$nullableErrors = [
    ['scalaire nullable', '<?php function f(?int $x): void {}', 'scalaire nullable non supporté'],
    ['tableau nullable', '<?php function f(?array $x): void {}', 'tableau nullable non supporté'],
    ['void nullable', '<?php function f(?void $x): void {}', 'type nullable invalide'],
    ['variable null non typée', '<?php $x = null;', 'affectation de null à une variable non typée'],
    ['null vers non nullable', '<?php class User {} $user = new User(); $user = null;', 'n’est pas nullable'],
    ['null en argument', '<?php class User {} function f(User $user): void {} f(null);', 'attend User, reçu null'],
    ['null en retour', '<?php function f(): string { return null; }', 'return attend string, reçu null'],
    ['élément nullable', '<?php class User {} /** @return array<?User> */ function f(): array { return []; }', 'type d’élément de tableau non supporté'],
    ['echo objet nullable', '<?php class User {} function f(?User $user): void { echo $user; }', 'echo ne peut pas afficher une valeur de type ?User'],
];

foreach ($nullableErrors as $index => [$label, $source, $needle]) {
    try {
        $compilePhp('nullable-error-' . $index, $source, 'c');
        T::ok(false, 'nullable : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'nullable : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

// ---------------------------------------------------------------------------
T::section('35. Namespaces, imports et compilation multi-fichiers');

$namespaceSource = <<<'PHP'
<?php
namespace App\Models;

class User {
    public string $name;

    public function __construct(string $name) {
        $this->name = $name;
    }
}

function helper(): string {
    return "helper";
}

class Service {
    public function label(User $user): string {
        return $user->name . ":" . helper();
    }
}

$user = new User("alice");
$service = new Service();
echo $service->label($user), "\n";
echo strlen("abc"), " ", \strlen("abcd"), "\n";
echo \App\Models\helper(), "\n";
PHP;

$namespaceC = $compilePhp('namespace-single', $namespaceSource, 'c');
$namespaceCSource = (string) file_get_contents($namespaceC['cPath']);
T::ok(str_contains($namespaceCSource, 'CyObj_App__Models__User'), 'namespace : type C');
T::ok(str_contains($namespaceCSource, 'fn_App__Models__helper'), 'namespace : fonction C');

if (!$hasGcc) {
    T::skip('gcc absent : exécution des namespaces non testée');
} else {
    $namespaceElf = $compilePhp('namespace-single', $namespaceSource, 'elf');
    T::same(
        "alice:helper\n3 4\nhelper\n",
        shell_exec(escapeshellarg((string) $namespaceElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : namespace, fonction locale et repli global',
    );
}

$multiRoot = $phpRoot . '/namespace-project';
mkdir($multiRoot . '/.cyonima', 0775, true);
mkdir($multiRoot . '/src/Contracts', 0775, true);
mkdir($multiRoot . '/src/Models', 0775, true);
mkdir($multiRoot . '/src/Services', 0775, true);
file_put_contents($multiRoot . '/.cyonima/compiler.json', json_encode([
    'sources' => ['src/Contracts', 'src/Models', 'src/Services', 'src/Utils.php'],
], JSON_PRETTY_PRINT));
file_put_contents($multiRoot . '/src/Contracts/Named.php', <<<'PHP'
<?php
namespace App\Contracts;

interface Named {
    public function name(): string;
}
PHP);
file_put_contents($multiRoot . '/src/Models/User.php', <<<'PHP'
<?php
namespace App\Models;

use App\Contracts\Named;

class User implements Named {
    public string $label;

    public function __construct(string $label) {
        $this->label = $label;
    }

    public function name(): string {
        return $this->label;
    }
}
PHP);
file_put_contents($multiRoot . '/src/Models/Collection.php', <<<'PHP'
<?php
namespace App\Models;

class Collection {
    /** @param array<User> $users */
    public function join(array $users): string {
        $result = "";

        foreach ($users as $user) {
            $result = $result . $user->name() . ",";
        }

        return $result;
    }
}
PHP);
file_put_contents($multiRoot . '/src/Services/UserService.php', <<<'PHP'
<?php
namespace App\Services;

use App\Models\User as Member;

class UserService {
    public function describe(Member $user): string {
        return "user:" . $user->name();
    }
}
PHP);
file_put_contents($multiRoot . '/src/Utils.php', <<<'PHP'
<?php
namespace App;

function localHelper(): string {
    return "local";
}
PHP);
file_put_contents($multiRoot . '/src/main.php', <<<'PHP'
<?php
namespace App;

use App\Models\Collection;
use App\Models\User;
use App\Services\UserService;

$user = new User("alice");
$service = new UserService();
echo $service->describe($user), "\n";
echo localHelper(), " ", strlen("abcd"), "\n";

$users = [new User("a"), new User("b")];
$collection = new Collection();
echo $collection->join($users), "\n";
PHP);

$multiResult = (new PhpCompiler($multiRoot))->compile(
    $multiRoot . '/src/main.php',
    null,
    'c',
);
$multiCSource = (string) file_get_contents($multiResult['cPath']);
T::ok(str_contains($multiCSource, 'CyObj_App__Contracts__Named'), 'multi : interface');
T::ok(str_contains($multiCSource, 'CyObj_App__Models__User'), 'multi : modèle');
T::ok(str_contains($multiCSource, 'CyObj_App__Services__UserService'), 'multi : service');

if (!$hasGcc) {
    T::skip('gcc absent : exécution multi-fichiers non testée');
} else {
    $multiElf = (new PhpCompiler($multiRoot))->compile(
        $multiRoot . '/src/main.php',
        null,
        'elf',
    );
    T::same(
        "user:alice\nlocal 4\na,b,\n",
        shell_exec(escapeshellarg((string) $multiElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : imports, interfaces et collections multi-fichiers',
    );
}

$namespaceUiSource = <<<'PHP'
<?php
namespace App;

use Application;
use Window;
use Box;
use Label;
use Button;

$app = new Application('com.example.namespaceui');
$window = new Window('Namespace UI', 400, 200);
$box = new Box(true, 8);
$label = new Label('Bonjour');
$button = new Button('Cliquer');
$box->append($label);
$box->append($button);
$window->setChild($box);
$button->onClicked(function () use ($label): void {
    $label->setText('Clique');
});
$window->present();
$app->after(100, fn () => $app->quit());
$app->run();
PHP;
$namespaceUiC = $compilePhp('namespace-ui', $namespaceUiSource, 'c');
$namespaceUiCSource = (string) file_get_contents($namespaceUiC['cPath']);
T::ok(
    str_contains($namespaceUiCSource, '#include "cyonima_ui.h"')
    && str_contains($namespaceUiCSource, 'CyObj_Application'),
    'namespace : imports de la façade UI',
);

$namespaceErrors = [
    ['deux namespaces', '<?php namespace A; namespace B;', 'une seule déclaration namespace'],
    ['namespace après use', '<?php use A\B; namespace C;', 'namespace doit précéder use'],
    ['use après déclaration', '<?php namespace A; class X {} use A\Y;', 'use doit précéder les déclarations'],
    ['use function', '<?php namespace A; use function A\foo;', 'use function non supporté'],
    ['use groupé', '<?php namespace A; use A\{B, C};', 'use groupé non supporté'],
    ['alias dupliqué', '<?php namespace A; use A\B; use C\B;', 'alias dupliqué'],
    ['classe inconnue', '<?php namespace A; new Missing();', 'classe inconnue : A\Missing'],
    ['fonction inconnue', '<?php namespace A; missing();', 'fonction inconnue : A\missing'],
];

foreach ($namespaceErrors as $index => [$label, $source, $needle]) {
    try {
        $compilePhp('namespace-error-' . $index, $source, 'c');
        T::ok(false, 'namespace : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'namespace : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

$multErrorRoot = $phpRoot . '/namespace-errors';
mkdir($multErrorRoot . '/.cyonima', 0775, true);
mkdir($multErrorRoot . '/src', 0775, true);
file_put_contents($multErrorRoot . '/.cyonima/compiler.json', '{"sources":["src"]}');
file_put_contents($multErrorRoot . '/src/main.php', "<?php\nnamespace App;\necho \"main\\n\";\n");
file_put_contents($multErrorRoot . '/src/A.php', "<?php\nnamespace App;\nclass User {}\n");
file_put_contents($multErrorRoot . '/src/B.php', "<?php\nnamespace App;\nclass User {}\n");

try {
    (new PhpCompiler($multErrorRoot))->compile($multErrorRoot . '/src/main.php', null, 'c');
    T::ok(false, 'multi : erreur classe dupliquée');
} catch (Cyonima\Compiler\CompilerException $exception) {
    T::ok(
        str_contains($exception->getMessage(), 'type déjà défini : App\User'),
        'multi : erreur classe dupliquée',
        $exception->getMessage(),
    );
}

file_put_contents($multErrorRoot . '/src/B.php', "<?php\nnamespace App;\necho \"side\";\n");

try {
    (new PhpCompiler($multErrorRoot))->compile($multErrorRoot . '/src/main.php', null, 'c');
    T::ok(false, 'multi : code exécutable non-entry refusé');
} catch (Cyonima\Compiler\CompilerException $exception) {
    T::ok(
        str_contains($exception->getMessage(), 'ne peut contenir que des déclarations'),
        'multi : code exécutable non-entry refusé',
        $exception->getMessage(),
    );
}

file_put_contents($multErrorRoot . '/.cyonima/compiler.json', '{"sources":["missing"]}');

try {
    (new PhpCompiler($multErrorRoot))->compile($multErrorRoot . '/src/main.php', null, 'c');
    T::ok(false, 'multi : source introuvable refusée');
} catch (Cyonima\Compiler\CompilerException $exception) {
    T::ok(
        str_contains($exception->getMessage(), 'Source déclarée introuvable'),
        'multi : source introuvable refusée',
        $exception->getMessage(),
    );
}

// ---------------------------------------------------------------------------
T::section('36. JSON natif — encodage et décodage typé');

$jsonSource = <<<'PHP'
<?php
echo json_encode(42), "\n";
echo json_encode(1.5), "\n";
echo json_encode(true), " ", json_encode(false), "\n";
echo json_encode("a\n\"b"), "\n";
echo json_encode([1, 2, 3]), "\n";
echo json_encode(["a" => 1, "b" => 2]), "\n";
echo json_encode(["x" => "y"]), "\n";
echo json_encode(null), "\n";

$n = json_decode_int("42");
$f = json_decode_float("2.5");
$b = json_decode_bool("true");
$s = json_decode_string("\"hello\"");
echo $n, " ", $f, " ", $b, " ", $s, "\n";

$list = json_decode_array_int("[1, 2, 3]");
echo count($list), " ", $list[1], "\n";

$table = json_decode_map_string("{\"a\": \"x\", \"b\": \"y\"}");
echo count($table), " ", $table["b"], "\n";
PHP;

$jsonC = $compilePhp('json-basic', $jsonSource, 'c');
$jsonCSource = (string) file_get_contents($jsonC['cPath']);
T::ok(str_contains($jsonCSource, 'cy_json_encode_array'), 'JSON : encodage de tableau');
T::ok(str_contains($jsonCSource, 'cy_json_encode_map'), 'JSON : encodage de table');
T::ok(str_contains($jsonCSource, 'fn_json_decode_map_string'), 'JSON : décodage typé');

if (!$hasGcc) {
    T::skip('gcc absent : exécution JSON non testée');
} else {
    $jsonElf = $compilePhp('json-basic', $jsonSource, 'elf');
    $jsonExpected = <<<'TXT'
42
1.5
true false
"a\n\"b"
[1,2,3]
{"a":1,"b":2}
{"x":"y"}
null
42 2.5 1 hello
3 2
2 y

TXT;
    T::same(
        $jsonExpected,
        shell_exec(escapeshellarg((string) $jsonElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : encodage et décodage JSON',
    );

    $jsonErrorElf = $compilePhp('json-errors-runtime', <<<'PHP'
<?php
$emptyList = json_decode_array_int("[]");
$emptyMap = json_decode_map_int("{}");
echo count($emptyList), " ", count($emptyMap), "\n";
echo json_decode_string("\"caf\\u00e9\""), "\n";
$strings = json_decode_array_string("[\"a\",\"b\"]");
echo $strings[0], "\n";

try {
    json_decode_int("nope");
} catch (string $error) {
    echo "int: ", $error, "\n";
}

try {
    json_decode_array_int("[1, \"x\"]");
} catch (string $error) {
    echo "array: ", $error, "\n";
}

try {
    json_decode_map_string("{\"a\": 1}");
} catch (string $error) {
    echo "map: ", $error, "\n";
}
PHP, 'elf');
    T::same(
        "0 0\ncafé\na\nint: entier JSON attendu\narray: entier JSON attendu\nmap: chaîne JSON attendue\n",
        shell_exec(escapeshellarg((string) $jsonErrorElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : JSON vide, Unicode et erreurs typées',
    );

    $jsonNullableElf = $compilePhp('json-nullable', <<<'PHP'
<?php
function encode(?string $value): string {
    return json_encode($value);
}

echo encode(null), " ", encode("x"), "\n";
PHP, 'elf');
    T::same(
        "null \"x\"\n",
        shell_exec(escapeshellarg((string) $jsonNullableElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : JSON d’une chaîne nullable',
    );
}

$jsonErrors = [
    ['objet', '<?php class User {} $user = new User(); echo json_encode($user);', 'json_encode ne supporte pas le type User'],
    ['tableau d’objets', '<?php class User {} $users = [new User()]; echo json_encode($users);', 'json_encode ne supporte pas le type array<User>'],
    ['closure', '<?php $fn = fn (): int => 1; echo json_encode($fn);', 'json_encode ne supporte pas le type callable():int'],
    ['argument decode', '<?php echo json_decode_int(1);', 'json_decode_int attend string, reçu int'],
];

foreach ($jsonErrors as $index => [$label, $source, $needle]) {
    try {
        $compilePhp('json-error-' . $index, $source, 'c');
        T::ok(false, 'JSON : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'JSON : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

// ---------------------------------------------------------------------------
T::section('37. Hiérarchie d’exceptions et finally');

$exceptionSource = <<<'PHP'
<?php
class AppException extends RuntimeException {
}

function classify(bool $app, int $mode): string {
    $result = "";

    try {
        if ($app) {
            throw new AppException("app");
        }

        if ($mode === 1) {
            throw new RuntimeException("rt");
        }

        $result = "none";
    } catch (AppException $e) {
        $result = "App:" . $e->getMessage();
    } catch (RuntimeException $e) {
        $result = "Rt:" . $e->getMessage();
    } catch (Exception $e) {
        $result = "Ex:" . $e->getMessage();
    } finally {
        $result = $result . "+F";
    }

    return $result;
}

function early(): string {
    try {
        return "try";
    } finally {
        echo "cleanup\n";
    }
}

function catchThrow(): string {
    try {
        throw new RuntimeException("first");
    } catch (RuntimeException $e) {
        throw new RuntimeException("second");
    } finally {
        echo "catch-finally\n";
    }
}

echo classify(true, 0), "\n";
echo classify(false, 1), "\n";
echo classify(false, 0), "\n";
echo early(), "\n";

try {
    catchThrow();
} catch (RuntimeException $e) {
    echo "outer: ", $e->getMessage(), "\n";
}

$i = 0;

while ($i < 1000) {
    $tmp = strval($i);
    $i = $i + 1;
}

try {
    throw new RuntimeException("survivor");
} catch (Exception $e) {
    echo "gc: ", $e->getMessage(), "\n";
}
PHP;

$exceptionC = $compilePhp('exception-hierarchy', $exceptionSource, 'c');
$exceptionCSource = (string) file_get_contents($exceptionC['cPath']);
T::ok(str_contains($exceptionCSource, 'cy_throw_object'), 'exceptions : throw objet');
T::ok(str_contains($exceptionCSource, 'cy_exception_matches'), 'exceptions : dispatch de type');
T::ok(str_contains($exceptionCSource, 'cy_exception_suspend'), 'exceptions : finally et propagation');
T::ok(str_contains($exceptionCSource, 'cy_class_is_a'), 'exceptions : hiérarchie de classes');

if (!$hasGcc) {
    T::skip('gcc absent : exécution des exceptions non testée');
} else {
    $exceptionElf = $compilePhp('exception-hierarchy', $exceptionSource, 'elf');
    T::same(
        "App:app+F\nRt:rt+F\nnone+F\ncleanup\ntry\ncatch-finally\nouter: second\ngc: survivor\n",
        shell_exec(escapeshellarg((string) $exceptionElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : hiérarchie, catch multiple, finally et GC',
    );

    $exceptionCustomElf = $compilePhp('exception-custom', <<<'PHP'
<?php
class DetailedException extends Exception {
    public int $detail;

    public function __construct(string $message, int $detail) {
        parent::__construct($message);
        $this->detail = $detail;
    }
}

try {
    throw new DetailedException("detail", 7);
} catch (DetailedException $e) {
    echo $e->getMessage(), " ", $e->detail, "\n";
}
PHP, 'elf');
    T::same(
        "detail 7\n",
        shell_exec(escapeshellarg((string) $exceptionCustomElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : exception personnalisée et parent::__construct',
    );

    $exceptionNamespaceElf = $compilePhp('exception-namespace', <<<'PHP'
<?php
namespace App;

use Exception;

class MyException extends Exception {
}

try {
    throw new MyException("namespace");
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
PHP, 'elf');
    T::same(
        "namespace\n",
        shell_exec(escapeshellarg((string) $exceptionNamespaceElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : exception dans un namespace',
    );

    $exceptionFinallyElf = $compilePhp('exception-finally-uncaught', <<<'PHP'
<?php
try {
    throw new RuntimeException("gone");
} finally {
    echo "normal-finally\n";
}
PHP, 'elf');
    $exceptionFinallyOutput = (string) shell_exec(
        escapeshellarg((string) $exceptionFinallyElf['binaryPath']) . ' 2>&1',
    );
    T::ok(
        str_contains($exceptionFinallyOutput, "normal-finally")
            && str_contains($exceptionFinallyOutput, "exception non capturée : gone"),
        'compilateur PHP : finally avant propagation non capturée',
        $exceptionFinallyOutput,
    );
}

$exceptionErrors = [
    ['throw objet non Exception', '<?php class Plain {} $plain = new Plain(); throw $plain;', 'throw attend une chaîne ou une Exception'],
    ['catch objet non Exception', '<?php class Plain {} try { echo "x"; } catch (Plain $e) { echo "c"; }', 'catch attend string ou une Exception'],
    ['catch mixte', '<?php try { echo "x"; } catch (string $e) { echo $e; } catch (Exception $e) { echo $e; }', 'catch string et catch d’exception non mélangeables'],
    ['break dans try/finally', '<?php try { switch (1) { case 1: break; } } finally { echo "f"; }', 'break dans try/finally non supporté'],
    ['try vide', '<?php try { echo "x"; }', 'try doit avoir au moins un catch ou un finally'],
    ['finally hors try', '<?php finally { echo "f"; }', 'finally doit suivre try/catch'],
];

foreach ($exceptionErrors as $index => [$label, $source, $needle]) {
    try {
        $compilePhp('exception-error-' . $index, $source, 'c');
        T::ok(false, 'exceptions : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'exceptions : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

// ---------------------------------------------------------------------------
T::section('38. Cyonima\\Console — commandes natives');

$consoleSource = <<<'PHP'
<?php
namespace App;

use Cyonima\Console\Application;
use Cyonima\Console\Command;
use Cyonima\Console\Input;
use Cyonima\Console\Output;

final class GreetCommand extends Command {
    protected function configure(): void {
        $this->setName('greet');
        $this->setDescription('Salue une personne');
        $this->addArgument('name', true, 'Nom à saluer');
        $this->addOption('shout', 's', false, 'Exclamation');
    }

    public function run(Input $input, Output $output): int {
        $name = $input->argument('name', 'world');
        $message = 'Hello ' . $name;

        if ($input->hasOption('shout')) {
            $message = $message . '!';
        }

        $output->writeln($message);

        return 0;
    }
}

$app = new Application('cyonima-demo', '1.0.0');
$app->add(new GreetCommand());

return $app->run(cli_args());
PHP;

$consoleC = $compilePhp('console-app', $consoleSource, 'c');
$consoleCSource = (string) file_get_contents($consoleC['cPath']);
T::ok(str_contains($consoleCSource, 'cy_cli_args'), 'console : arguments CLI');
T::ok(str_contains($consoleCSource, 'CyObj_Cyonima__Console__Application'), 'console : façade Application');
T::ok(str_contains($consoleCSource, 'fn_App__GreetCommand__run'), 'console : commande compilée');
T::ok(str_contains($consoleCSource, 'cy_cli_error'), 'console : sortie d’erreur');

if (!$hasGcc) {
    T::skip('gcc absent : exécution de la console non testée');
} else {
    $consoleElf = $compilePhp('console-app', $consoleSource, 'elf');
    $binary = escapeshellarg((string) $consoleElf['binaryPath']);

    T::same(
        "Hello Alice\n",
        shell_exec($binary . ' greet Alice 2>&1'),
        'console : argument positionnel',
    );
    T::same(
        "Hello Bob!\n",
        shell_exec($binary . ' greet Bob --shout 2>&1'),
        'console : option longue',
    );
    T::same(
        "Hello Bob!\n",
        shell_exec($binary . ' greet Bob -s 2>&1'),
        'console : option courte',
    );
    T::same(
        "cyonima-demo 1.0.0\n",
        shell_exec($binary . ' --version 2>&1'),
        'console : --version',
    );

    $listOutput = (string) shell_exec($binary . ' list 2>&1');
    T::ok(
        str_contains($listOutput, 'greet')
            && str_contains($listOutput, 'Salue une personne'),
        'console : liste des commandes',
        $listOutput,
    );

    $helpOutput = (string) shell_exec($binary . ' help greet 2>&1');
    T::ok(
        str_contains($helpOutput, 'Usage:')
            && str_contains($helpOutput, 'name (requis)')
            && str_contains($helpOutput, '--shout, -s'),
        'console : aide de commande',
        $helpOutput,
    );

    $lines = [];
    $status = 0;
    exec($binary . ' greet 2>&1', $lines, $status);
    T::same(1, $status, 'console : argument requis, code de sortie');
    T::ok(
        str_contains(implode("\n", $lines), 'argument manquant : name'),
        'console : argument requis, message',
        implode("\n", $lines),
    );

    $lines = [];
    $status = 0;
    exec($binary . ' missing 2>&1', $lines, $status);
    T::same(1, $status, 'console : commande inconnue, code de sortie');
    T::ok(
        str_contains(implode("\n", $lines), 'commande inconnue : missing'),
        'console : commande inconnue, message',
        implode("\n", $lines),
    );

    $lines = [];
    $status = 0;
    exec($binary . ' greet Alice --nope 2>&1', $lines, $status);
    T::same(1, $status, 'console : option inconnue, code de sortie');
    T::ok(
        str_contains(implode("\n", $lines), 'option inconnue : --nope'),
        'console : option inconnue, message',
        implode("\n", $lines),
    );

    $stringsElf = $compilePhp('console-string-helpers', <<<'PHP'
<?php
if (str_starts_with('abcdef', 'abc')) {
    echo "start\n";
}

if (str_contains('abcdef', 'cde')) {
    echo "contains\n";
}

echo substr('abcdef', 2), "\n";
echo substr_len('abcdef', 2, 3), "\n";
PHP, 'elf');
    T::same(
        "start\ncontains\ncdef\ncde\n",
        shell_exec(escapeshellarg((string) $stringsElf['binaryPath']) . ' 2>&1'),
        'console : primitives de chaînes CLI',
    );
}

// ---------------------------------------------------------------------------
T::section('39. Cyonima\\Database — repositories SQLite et migrations');

$databaseSource = <<<'PHP'
<?php
namespace App;

use Cyonima\Database\Connection;
use Cyonima\Database\Migration;
use Cyonima\Database\Migrator;
use Cyonima\Database\Repository;

final class CreateUsers extends Migration {
    public function version(): int {
        return 1;
    }

    public function up(Connection $connection): void {
        $connection->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
    }
}

final class User {
    public int $id;
    public string $name;

    public function __construct(int $id, string $name) {
        $this->id = $id;
        $this->name = $name;
    }
}

final class UserRepository extends Repository {
    public function __construct(Connection $connection) {
        parent::__construct($connection, 'users');
    }

    public function create(string $name): User {
        $this->execute(
            'INSERT INTO users (name) VALUES (' . $this->connection->quote($name) . ')'
        );

        return new User($this->lastId(), $name);
    }

    public function find(int $id): ?User {
        $row = $this->row('SELECT id, name FROM users WHERE id = ' . strval($id));

        if (count($row) === 0) {
            return null;
        }

        return new User(intval($row['id']), $row['name']);
    }
}

$db = new Connection(':memory:');
$migrator = new Migrator($db);
$migrator->add(new CreateUsers());
echo 'migrations: ', $migrator->run(), "\n";
echo 'migrations again: ', $migrator->run(), "\n";

$users = new UserRepository($db);
$user = $users->create("O'Reilly");
echo $user->id, ' ', $user->name, "\n";

$found = $users->find($user->id);

if ($found !== null) {
    echo 'found: ', $found->name, "\n";
}

if ($users->exists($user->id)) {
    echo "exists: yes\n";
}

echo 'count: ', $users->count(), "\n";
$users->delete($user->id);
echo 'after delete: ', $users->count(), "\n";
$db->close();
PHP;

$databaseC = $compilePhp('database-app', $databaseSource, 'c');
$databaseCSource = (string) file_get_contents($databaseC['cPath']);
T::ok(str_contains($databaseCSource, 'CyObj_Cyonima__Database__Connection'), 'database : Connection');
T::ok(str_contains($databaseCSource, 'sqlite_query_row_string'), 'database : row map SQLite');
T::ok(str_contains($databaseCSource, 'cyonima_migrations'), 'database : table de migrations');
T::ok(str_contains($databaseCSource, 'sqlite_quote'), 'database : quoting SQL');

if (!$hasGcc || !$hasSqlite) {
    T::skip('gcc ou SQLite absent : exécution Database non testée');
} else {
    $databaseElf = $compilePhp('database-app', $databaseSource, 'elf');
    T::same(
        "migrations: 1\nmigrations again: 0\n1 O'Reilly\nfound: O'Reilly\nexists: yes\ncount: 1\nafter delete: 0\n",
        shell_exec(escapeshellarg((string) $databaseElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : migrations, repository et quoting SQLite',
    );

    $databaseErrorElf = $compilePhp('database-open-error', <<<'PHP'
<?php
use Cyonima\Database\Connection;
use Exception;

try {
    $db = new Connection('/nonexistent/cyonima/database.sqlite');
    echo "unexpected\n";
} catch (Exception $error) {
    echo $error->getMessage(), "\n";
}
PHP, 'elf');
    T::ok(
        str_contains(
            (string) shell_exec(escapeshellarg((string) $databaseErrorElf['binaryPath']) . ' 2>&1'),
            'ouverture SQLite impossible',
        ),
        'compilateur PHP : erreur d’ouverture SQLite',
    );
}

// ---------------------------------------------------------------------------
T::section('40. Cyonima\\Validation — règles typées');

$validationSource = <<<'PHP'
<?php
namespace App;

use Cyonima\Validation\Email;
use Cyonima\Validation\In;
use Cyonima\Validation\Integer;
use Cyonima\Validation\MinLength;
use Cyonima\Validation\MinValue;
use Cyonima\Validation\Required;
use Cyonima\Validation\ValidationException;
use Cyonima\Validation\Validator;

$validator = new Validator();
$validator->add('name', new Required());
$validator->add('name', new MinLength(3));
$validator->add('email', new Email());
$validator->add('age', new Integer());
$validator->add('age', new MinValue(18));
$validator->add('role', new In(['admin', 'user']));

$invalid = ['name' => 'Al', 'email' => 'bad', 'age' => '12', 'role' => 'guest'];
$errors = $validator->validate($invalid);
echo count($errors), "\n";

foreach ($errors as $field => $message) {
    echo $field, ': ', $message, "\n";
}

$valid = ['name' => 'Alice', 'email' => 'alice@example.com', 'age' => '30', 'role' => 'admin'];
$validErrors = $validator->validate($valid);
echo 'valid errors: ', count($validErrors), "\n";

try {
    $validator->validateOrFail($invalid);
} catch (ValidationException $error) {
    echo 'exception: ', $error->getMessage(), "\n";
    echo 'errors: ', count($error->getErrors()), "\n";
}

echo trim('  hello  '), "\n";

if (is_numeric('1.5')) {
    echo "numeric\n";
}

if (is_integer('-42')) {
    echo "integer\n";
}
PHP;

$validationC = $compilePhp('validation-app', $validationSource, 'c');
$validationCSource = (string) file_get_contents($validationC['cPath']);
T::ok(str_contains($validationCSource, 'CyObj_Cyonima__Validation__Validator'), 'validation : Validator');
T::ok(str_contains($validationCSource, 'CyVTable_Cyonima__Validation__Rule'), 'validation : interface Rule');
T::ok(str_contains($validationCSource, 'CyObj_Cyonima__Validation__ValidationException'), 'validation : exception');

if (!$hasGcc) {
    T::skip('gcc absent : exécution de la validation non testée');
} else {
    $validationElf = $compilePhp('validation-app', $validationSource, 'elf');
    $validationExpected = <<<'TXT'
4
name: name doit contenir au moins 3 caractères
email: email doit être une adresse email valide
age: age doit être supérieur ou égal à 18
role: role doit être une valeur autorisée
valid errors: 0
exception: name doit contenir au moins 3 caractères; email doit être une adresse email valide; age doit être supérieur ou égal à 18; role doit être une valeur autorisée
errors: 4
hello
numeric
integer

TXT;
    T::same(
        $validationExpected,
        shell_exec(escapeshellarg((string) $validationElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : règles, erreurs, exception et helpers',
    );
}

// ---------------------------------------------------------------------------
T::section('41. Compilateur PHP — templates .ui vers GTK');

$templateRoot = sys_get_temp_dir() . '/cyonima_templates_' . getmypid();
$removeTree($templateRoot);
mkdir($templateRoot . '/src', 0775, true);
mkdir($templateRoot . '/templates', 0775, true);

$templateSource = <<<'UI'
@params(array<App\Todo> $todos, string $title, callable():void $onAdd)

<window title="{{ $title }}" width="420" height="480">
    <box vertical="true" spacing="8">
        <label text="{{ $title }}" />
        @foreach ($todos as $todo)
            <box vertical="false" spacing="6">
                <label>{{ $todo->title }}</label>
                <label>{{ $todo->status }}</label>
            </box>
        @endforeach
        @if (count($todos) === 0)
            <label>Rien à afficher</label>
        @else
            <label>Total : {{ count($todos) }}</label>
        @endif
        <entry placeholder="Nouvelle tâche" />
        <button text="Ajouter" on-click="{{ $onAdd }}" />
    </box>
</window>
UI;

file_put_contents($templateRoot . '/templates/todo-list.ui', $templateSource);

$templateAppSource = <<<'PHP'
<?php
namespace App;

use Application;
use Cyonima\Templates\TodoList;

final class Todo
{
    public string $title;
    public string $status;

    public function __construct(string $title, string $status)
    {
        $this->title = $title;
        $this->status = $status;
    }
}

$todos = [];
$todos[] = new Todo('Compiler', 'fait');
$todos[] = new Todo('Templates', 'en cours');

$onAdd = function (): void {
    echo "add\n";
};

$application = new Application('com.cyonima.templates.test');
$window = TodoList::build($todos, 'Tâches', $onAdd);
$window->present();
$application->after(80, function () use ($application): void {
    $application->quit();
});
$application->run();
PHP;

file_put_contents($templateRoot . '/src/app.php', $templateAppSource);

$generatedTemplate = (new TemplateCompiler('TodoList'))->compile($templateSource);
T::ok(
    str_contains($generatedTemplate, 'namespace Cyonima\\Templates;'),
    'template : namespace généré',
);
T::ok(
    str_contains($generatedTemplate, 'public static function build(array $todos, string $title, callable():void $onAdd): Window'),
    'template : signature build()',
);
T::ok(
    str_contains($generatedTemplate, '@param array<\\App\\Todo> $todos'),
    'template : type de collection conservé',
);
T::ok(str_contains($generatedTemplate, 'foreach ($todos as $todo)'), 'template : boucle foreach');
T::ok(str_contains($generatedTemplate, '->onClicked(($onAdd))'), 'template : callback on-click');
T::ok(str_contains($generatedTemplate, "new Entry('Nouvelle tâche')"), 'template : entrée');

$templateC = (new PhpCompiler($templateRoot))->compile(
    $templateRoot . '/src/app.php',
    null,
    'c',
);
$templateCSource = (string) file_get_contents($templateC['cPath']);
T::ok(
    str_contains($templateCSource, '#include "cyonima_ui.h"'),
    'template : prélude UI forcé',
);
T::ok(
    str_contains($templateCSource, 'CyObj_Cyonima__Templates__TodoList'),
    'template : classe C générée',
);
T::ok(str_contains($templateCSource, 'cy_ui_window'), 'template : fenêtre GTK');
T::ok(str_contains($templateCSource, 'cy_ui_entry'), 'template : entrée GTK');
T::ok(str_contains($templateCSource, 'cy_ui_button'), 'template : bouton GTK');
T::ok(
    str_contains($templateCSource, 'Tâches'),
    'template : texte interpolé statique',
);

$exampleTemplateC = (new PhpCompiler(
    $templateRoot,
    [],
    [],
    [dirname(__DIR__) . '/examples/native-todo-list.ui'],
))->compile(
    dirname(__DIR__) . '/examples/native-templates.php',
    null,
    'c',
);
$exampleTemplateSource = (string) file_get_contents($exampleTemplateC['cPath']);
T::ok(
    str_contains($exampleTemplateSource, 'CyObj_Cyonima__Templates__NativeTodoList'),
    'template : exemple du dépôt',
);

$templateErrors = [
    ['balise inconnue', '<window><frame /></window>', 'balise inconnue <frame>'],
    ['attribut inconnu', '<window><label color="red">x</label></window>', 'attribut inconnu "color"'],
    ['balise non fermée', '<window><box>', 'balise <box> non fermée'],
    ['callback littéral', '<window><button text="x" on-click="handler" /></window>', 'doit être {{ $callable }}'],
];

foreach ($templateErrors as $index => [$label, $source, $needle]) {
    try {
        (new TemplateCompiler('Broken' . $index))->compile($source);
        T::ok(false, 'template : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'template : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

$templateGtk = trim((string) shell_exec('pkg-config --exists gtk4 2>/dev/null && echo yes || true')) === 'yes';
$templateDisplay = getenv('DISPLAY') !== false && getenv('DISPLAY') !== '';

if (!$hasGcc || !$templateGtk) {
    T::skip('GTK4 dev absent : exécution des templates non testée');
} elseif (!$templateDisplay) {
    T::skip('DISPLAY absent : exécution des templates non testée');
} else {
    $templateElf = (new PhpCompiler($templateRoot))->compile(
        $templateRoot . '/src/app.php',
        null,
        'elf',
    );
    $templateRunner = 'timeout 8 ' . escapeshellarg((string) $templateElf['binaryPath']);

    if (getenv('DBUS_SESSION_BUS_ADDRESS') === false
        && trim((string) shell_exec('command -v dbus-run-session 2>/dev/null')) !== '') {
        $templateRunner = 'dbus-run-session -- ' . $templateRunner;
    }

    $templateOut = [];
    $templateStatus = 0;
    exec($templateRunner . ' 2>&1', $templateOut, $templateStatus);
    T::ok(
        $templateStatus === 0,
        'template : application GTK exécutée',
        implode("\n", $templateOut),
    );
}

// ---------------------------------------------------------------------------
T::section('42. Cyonima\\Database — ORM léger (Entity, Table, transactions)');

$ormSource = <<<'PHP'
<?php
namespace App;

use Cyonima\Database\Connection;
use Cyonima\Database\Entity;
use Cyonima\Database\Migration;
use Cyonima\Database\Migrator;
use Cyonima\Database\Repository;
use Cyonima\Database\Table;
use RuntimeException;

final class CreateTodos extends Migration
{
    public function version(): int
    {
        return 1;
    }

    public function up(Connection $connection): void
    {
        $connection->exec(
            'CREATE TABLE todos (id INTEGER PRIMARY KEY, title TEXT, done TEXT)'
        );
    }
}

final class Todo extends Entity
{
    public string $title;
    public bool $done;

    public function __construct(string $title, bool $done)
    {
        parent::__construct();
        $this->title = $title;
        $this->done = $done;
    }

    public function table(): string
    {
        return 'todos';
    }

    /** @return array<string,string> */
    public function values(): array
    {
        $values = [];
        $values['title'] = $this->title;

        if ($this->done) {
            $values['done'] = '1';
        } else {
            $values['done'] = '0';
        }

        return $values;
    }

    /** @param array<string,string> $row */
    public function hydrate(array $row): void
    {
        $this->id = intval($row['id']);
        $this->title = $row['title'];
        $this->done = $row['done'] === '1';
    }
}

final class TodoRepository extends Repository
{
    public function __construct(Connection $connection)
    {
        parent::__construct($connection, 'todos');
    }

    public function find(int $id): ?Todo
    {
        $row = $this->store()->find($id);

        if (count($row) === 0) {
            return null;
        }

        $todo = new Todo('', false);
        $todo->hydrate($row);

        return $todo;
    }

    public function findOrFail(int $id): Todo
    {
        $row = $this->store()->find($id);

        if (count($row) === 0) {
            throw new RuntimeException('todo introuvable : ' . strval($id));
        }

        $todo = new Todo('', false);
        $todo->hydrate($row);

        return $todo;
    }

    /** @return array<Todo> */
    public function all(): array
    {
        $todos = [];
        $ids = $this->store()->ids();

        foreach ($ids as $id) {
            $row = $this->store()->find($id);

            if (count($row) > 0) {
                $todo = new Todo('', false);
                $todo->hydrate($row);
                $todos[] = $todo;
            }
        }

        return $todos;
    }

    public function findByTitle(string $title): ?Todo
    {
        $row = $this->store()->findWhere('title', $title);

        if (count($row) === 0) {
            return null;
        }

        $todo = new Todo('', false);
        $todo->hydrate($row);

        return $todo;
    }

    public function save(Todo $todo): int
    {
        return $todo->save($this->connection);
    }
}

$db = new Connection(':memory:');
$migrator = new Migrator($db);
$migrator->add(new CreateTodos());
echo 'migrations: ', $migrator->run(), "\n";

$todos = new TodoRepository($db);
$first = new Todo('Compiler l’ORM', false);
$firstId = $todos->save($first);
echo 'insert: ', $firstId, "\n";

if ($first->isNew()) {
    echo "state: new\n";
} else {
    echo "state: persisted\n";
}

$todos->save(new Todo('Tester les transactions', true));

$found = $todos->find($firstId);

if ($found !== null) {
    echo 'find: ', $found->title, "\n";

    if ($found->done) {
        echo "done: yes\n";
    } else {
        echo "done: no\n";
    }

    $found->done = true;
    $found->save($db);
}

$updated = $todos->findOrFail($firstId);

if ($updated->done) {
    echo "update: yes\n";
} else {
    echo "update: no\n";
}

$byTitle = $todos->findByTitle('Tester les transactions');

if ($byTitle !== null) {
    echo 'where: ', $byTitle->id, ' ', $byTitle->title, "\n";
}

$db->transaction(function () use ($todos): void {
    $todos->save(new Todo('Transaction', false));
});
echo 'after commit: ', $todos->store()->count(), "\n";

try {
    $db->transaction(function () use ($todos): void {
        $todos->save(new Todo('Annulé', false));
        throw new RuntimeException('rollback demandé');
    });
} catch (RuntimeException $error) {
    echo 'rollback: ', $error->getMessage(), "\n";
}
echo 'after rollback: ', $todos->store()->count(), "\n";

$all = $todos->all();
echo 'all: ', count($all), "\n";

foreach ($all as $todo) {
    echo '- ', $todo->title, "\n";
}

try {
    $table = new Table($db, 'todos; DROP TABLE todos');
    echo "unexpected\n";
} catch (RuntimeException $error) {
    echo 'guard: ', $error->getMessage(), "\n";
}

try {
    $table = new Table($db, '');
    echo "unexpected\n";
} catch (RuntimeException $error) {
    echo 'guard empty: ', $error->getMessage(), "\n";
}
$new = new Todo('Jamais enregistré', false);

if ($new->delete($db)) {
    echo "delete new: accepted\n";
} else {
    echo "delete new: refused\n";
}

if ($todos->findOrFail($firstId)->delete($db)) {
    echo "delete: done\n";
}

if ($todos->store()->exists($firstId)) {
    echo "after delete: exists\n";
} else {
    echo "after delete: missing\n";
}

$db->close();
PHP;

$ormC = $compilePhp('orm-app', $ormSource, 'c');
$ormCSource = (string) file_get_contents($ormC['cPath']);
T::ok(str_contains($ormCSource, 'CyObj_Cyonima__Database__Entity'), 'ORM : Entity');
T::ok(str_contains($ormCSource, 'CyObj_Cyonima__Database__Table'), 'ORM : Table');
T::ok(str_contains($ormCSource, 'fn_sqlite_query_column_int'), 'ORM : colonne d’identifiants');
T::ok(str_contains($ormCSource, 'fn_sqlite_query_row_string'), 'ORM : hydratation par row map');
T::ok(str_contains($ormCSource, 'fn_sqlite_prepare'), 'ORM : requêtes préparées');
T::ok(str_contains($ormCSource, 'fn_sqlite_bind_string'), 'ORM : bind des valeurs');
T::ok(str_contains($ormCSource, 'fn_sqlite_quote'), 'ORM : quoting disponible');

$exampleOrmC = (new PhpCompiler($phpRoot))->compile(
    dirname(__DIR__) . '/examples/native-orm.php',
    null,
    'c',
);
$exampleOrmSource = (string) file_get_contents($exampleOrmC['cPath']);
T::ok(
    str_contains($exampleOrmSource, 'CyObj_Cyonima__Database__Entity'),
    'ORM : exemple du dépôt',
);

if (!$hasGcc || !$hasSqlite) {
    T::skip('gcc ou SQLite absent : exécution de l’ORM non testée');
} else {
    $ormElf = $compilePhp('orm-app', $ormSource, 'elf');
    $ormExpected = <<<'TXT'
migrations: 1
insert: 1
state: persisted
find: Compiler l’ORM
done: no
update: yes
where: 2 Tester les transactions
after commit: 3
rollback: rollback demandé
after rollback: 3
all: 3
- Compiler l’ORM
- Tester les transactions
- Transaction
guard: identifiant SQL invalide : todos; DROP TABLE todos
guard empty: identifiant SQL vide
delete new: refused
delete: done
after delete: missing

TXT;
    T::same(
        $ormExpected,
        shell_exec(escapeshellarg((string) $ormElf['binaryPath']) . ' 2>&1'),
        'compilateur PHP : entités, repositories, transactions et gardes SQL',
    );
}

// ---------------------------------------------------------------------------
T::section('43. UI — widgets enrichis, classes CSS et templates');

$uiRoot = sys_get_temp_dir() . '/cyonima_ui_enrich_' . getmypid();
$removeTree($uiRoot);
mkdir($uiRoot . '/src', 0775, true);
mkdir($uiRoot . '/templates', 0775, true);

$richTemplateSource = <<<'UI'
@params(array<App\Todo> $todos, float $progress, bool $enabled, callable():void $onToggle)

<window title="Enrichi" width="420" height="520" class="app-window" tooltip="Démo">
    <box vertical="true" spacing="8" class="panel">
        <label text="Progression" class="title" />
        <progress-bar fraction="{{ $progress }}" class="bar" />
        <separator />
        <label class="hint" sensitive="{{ $enabled }}">Détail</label>

        @foreach ($todos as $todo)
            <check-button
                label="{{ $todo->title }}"
                active="{{ $todo->done }}"
                on-toggled="{{ $onToggle }}"
                class="item"
            />
        @endforeach

        <entry placeholder="Nouvelle tâche" tooltip="Saisie" class="new-task" />
        <button text="Fermer" class="primary" />
    </box>
</window>
UI;

$richAppSource = <<<'PHP'
<?php
namespace App;

use Application;
use Cyonima\Templates\Rich;

final class Todo
{
    public string $title;
    public bool $done;

    public function __construct(string $title, bool $done)
    {
        $this->title = $title;
        $this->done = $done;
    }
}

$todos = [];
$todos[] = new Todo('Structure', true);
$todos[] = new Todo('Widgets', false);

$onToggle = function (): void {
    echo "toggle\n";
};

$application = new Application('com.cyonima.ui.rich');
$application->loadCss(
    '.panel { padding: 12px; } .title { font-weight: bold; }'
);
$window = Rich::build($todos, 0.5, true, $onToggle);
$window->addClass('root');
$window->setTooltip('Fenêtre enrichie');
$window->setSensitive(true);
$window->present();
$application->after(60, function () use ($application): void {
    $application->quit();
});
$application->run();
PHP;

$richGenerated = (new TemplateCompiler('Rich'))->compile($richTemplateSource);
T::ok(
    str_contains($richGenerated, 'new CheckButton'),
    'template enrichi : check-button',
);
T::ok(
    str_contains($richGenerated, 'new ProgressBar'),
    'template enrichi : progress-bar',
);
T::ok(
    str_contains($richGenerated, 'new Separator(false)'),
    'template enrichi : separator',
);
T::ok(
    str_contains($richGenerated, "->addClass('panel')"),
    'template enrichi : classe CSS',
);
T::ok(
    str_contains($richGenerated, "->setTooltip('Démo')"),
    'template enrichi : tooltip',
);
T::ok(
    str_contains($richGenerated, '->setSensitive(($enabled))'),
    'template enrichi : sensitive',
);
T::ok(
    str_contains($richGenerated, '->onToggled(($onToggle))'),
    'template enrichi : on-toggled',
);

file_put_contents($uiRoot . '/templates/rich.ui', $richTemplateSource);
file_put_contents($uiRoot . '/src/app.php', $richAppSource);

$richC = (new PhpCompiler($uiRoot))->compile($uiRoot . '/src/app.php', null, 'c');
$richCSource = (string) file_get_contents($richC['cPath']);
T::ok(str_contains($richCSource, 'cy_ui_check_button'), 'UI enrichie : case à cocher');
T::ok(str_contains($richCSource, 'cy_ui_progress_bar'), 'UI enrichie : barre de progression');
T::ok(str_contains($richCSource, 'cy_ui_separator'), 'UI enrichie : séparateur');
T::ok(str_contains($richCSource, 'cy_ui_add_class'), 'UI enrichie : classes CSS');
T::ok(str_contains($richCSource, 'cy_ui_add_css'), 'UI enrichie : feuille CSS');
T::ok(str_contains($richCSource, 'cy_ui_on_toggle_closure'), 'UI enrichie : signal toggled');
T::ok(str_contains($richCSource, 'cy_ui_set_tooltip'), 'UI enrichie : tooltip');
T::ok(str_contains($richCSource, 'cy_ui_set_sensitive'), 'UI enrichie : sensibilité');

$facadeSource = <<<'PHP'
<?php
use Application;
use Box;
use CheckButton;
use Entry;
use Label;
use ProgressBar;
use Separator;
use Window;

$app = new Application('com.cyonima.widgets.facade');
$app->loadCss('.panel { padding: 8px; }');
$window = new Window('Widgets', 360, 240);
$box = new Box(true, 6);
$box->addClass('panel');
$label = new Label('Prêt');
$label->setTooltip('État');
$check = new CheckButton('Activer', true);
$bar = new ProgressBar();
$bar->setFraction(0.5);
$bar->addClass('bar');
$separator = new Separator(false);
$entry = new Entry('Nom');
$entry->setSensitive(false);
$box->append($label);
$box->append($check);
$box->append($bar);
$box->append($separator);
$box->append($entry);
$window->setChild($box);
$window->present();
PHP;

$facadeC = $compilePhp('ui-rich-facade', $facadeSource, 'c');
$facadeCSource = (string) file_get_contents($facadeC['cPath']);
T::ok(str_contains($facadeCSource, 'cy_ui_check_button'), 'façade : CheckButton');
T::ok(str_contains($facadeCSource, 'cy_ui_progress_bar'), 'façade : ProgressBar');
T::ok(str_contains($facadeCSource, 'cy_ui_separator'), 'façade : Separator');
T::ok(str_contains($facadeCSource, 'cy_ui_add_css'), 'façade : Application::loadCss');

$exampleWidgetsC = (new PhpCompiler($phpRoot))->compile(
    dirname(__DIR__) . '/examples/native-widgets.php',
    null,
    'c',
);
$exampleWidgetsSource = (string) file_get_contents($exampleWidgetsC['cPath']);
T::ok(
    str_contains($exampleWidgetsSource, 'cy_ui_check_button')
        && str_contains($exampleWidgetsSource, 'cy_ui_progress_bar'),
    'UI enrichie : exemple du dépôt',
);

$richErrors = [
    ['fraction invalide', '<window><progress-bar fraction="moitié" /></window>', 'nombre attendu pour "fraction"'],
    ['check-button sans texte', '<window><check-button /></window>', 'texte manquant pour <check-button>'],
    ['sensitive invalide', '<window><label sensitive="peut-être">x</label></window>', 'booléen attendu pour "sensitive"'],
];

foreach ($richErrors as $index => [$label, $source, $needle]) {
    try {
        (new TemplateCompiler('RichBroken' . $index))->compile($source);
        T::ok(false, 'template enrichi : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'template enrichi : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

$richGtk = trim((string) shell_exec('pkg-config --exists gtk4 2>/dev/null && echo yes || true')) === 'yes';
$richDisplay = getenv('DISPLAY') !== false && getenv('DISPLAY') !== '';

if (!$hasGcc || !$richGtk) {
    T::skip('GTK4 dev absent : exécution des widgets enrichis non testée');
} elseif (!$richDisplay) {
    T::skip('DISPLAY absent : exécution des widgets enrichis non testée');
} else {
    $richElf = (new PhpCompiler($uiRoot))->compile($uiRoot . '/src/app.php', null, 'elf');
    $richRunner = 'timeout 8 ' . escapeshellarg((string) $richElf['binaryPath']);

    if (getenv('DBUS_SESSION_BUS_ADDRESS') === false
        && trim((string) shell_exec('command -v dbus-run-session 2>/dev/null')) !== '') {
        $richRunner = 'dbus-run-session -- ' . $richRunner;
    }

    $richOut = [];
    $richStatus = 0;
    exec($richRunner . ' 2>&1', $richOut, $richStatus);
    T::ok(
        $richStatus === 0,
        'UI enrichie : template GTK exécuté',
        implode("\n", $richOut),
    );

    $widgetSource = <<<'PHP'
<?php
use Application;
use CheckButton;
use ProgressBar;
use Separator;

$app = new Application('com.cyonima.widgets.values');
$check = new CheckButton('Activer', true);
$bar = new ProgressBar();
$bar->setFraction(0.25);
$separator = new Separator(true);
$separator->setSensitive(false);

if ($check->active()) {
    echo "active=yes\n";
}

if ($bar->fraction() > 0.2 && $bar->fraction() < 0.3) {
    echo "fraction=ok\n";
}

$check->setActive(false);

if (!$check->active()) {
    echo "inactive=yes\n";
}
PHP;

    $widgetElf = $compilePhp('ui-rich-values', $widgetSource, 'elf');
    $widgetRunner = 'timeout 8 ' . escapeshellarg((string) $widgetElf['binaryPath']);

    if (getenv('DBUS_SESSION_BUS_ADDRESS') === false
        && trim((string) shell_exec('command -v dbus-run-session 2>/dev/null')) !== '') {
        $widgetRunner = 'dbus-run-session -- ' . $widgetRunner;
    }

    $widgetOut = [];
    $widgetStatus = 0;
    exec($widgetRunner . ' 2>&1', $widgetOut, $widgetStatus);
    $widgetOutputText = implode("\n", $widgetOut);
    T::ok(
        str_contains($widgetOutputText, 'active=yes'),
        'UI enrichie : CheckButton actif',
        $widgetOutputText,
    );
    T::ok(
        str_contains($widgetOutputText, 'fraction=ok'),
        'UI enrichie : fraction conservée',
        $widgetOutputText,
    );
    T::ok(
        str_contains($widgetOutputText, 'inactive=yes'),
        'UI enrichie : CheckButton désactivé',
        $widgetOutputText,
    );
    T::ok($widgetStatus === 0, 'UI enrichie : widgets exécutés');
}

$removeTree($uiRoot);

// ---------------------------------------------------------------------------
T::section('44. UI — réduction des limites : ids, styles, images, callbacks d’état');

$limitsRoot = sys_get_temp_dir() . '/cyonima_ui_limits_' . getmypid();
$removeTree($limitsRoot);
mkdir($limitsRoot . '/src', 0775, true);
mkdir($limitsRoot . '/templates', 0775, true);

$limitsTemplate = <<<'UI'
@params(bool $enabled, callable(bool):void $onToggle)

<window title="Limites" width="360" height="420" id="limit-window" class="limit-window">
    <style>
        #limit-window { background: #101418; }
        .panel { padding: 8px; }
    </style>
    <box vertical="true" spacing="6" id="limit-panel" class="panel">
        <scrolled height="120" spacing="4">
            <check-button label="A" active="true" on-toggled="{{ $onToggle }}" />
            <check-button label="B" on-toggled="{{ $onToggle }}" />
        </scrolled>
        <image src="packaging/icon/com.cyonima.Ide.svg" width="32" height="32" />
        <label id="limit-state" sensitive="{{ $enabled }}">État</label>
    </box>
</window>
UI;

$limitsApp = <<<'PHP'
<?php
namespace App;

use Application;
use Cyonima\Templates\Limits;

$onToggle = function (bool $active): void {
    echo "toggle\n";
};

$application = new Application('com.cyonima.ui.limits');
$window = Limits::build(true, $onToggle);
$window->present();
$application->after(60, function () use ($application): void {
    $application->quit();
});
$application->run();
PHP;

$limitsGenerated = (new TemplateCompiler('Limits'))->compile($limitsTemplate);
T::ok(str_contains($limitsGenerated, 'new ScrolledWindow()'), 'limites : balise scrolled');
T::ok(str_contains($limitsGenerated, '->setChild('), 'limites : contenu défilant');
T::ok(str_contains($limitsGenerated, 'new Image('), 'limites : balise image');
T::ok(str_contains($limitsGenerated, '->setSize(32, 32)'), 'limites : taille d’image');
T::ok(str_contains($limitsGenerated, "->setName('limit-panel')"), 'limites : identifiant de widget');
T::ok(str_contains($limitsGenerated, '$window->loadCss('), 'limites : style dans le template');
T::ok(str_contains($limitsGenerated, '->onToggled(($onToggle))'), 'limites : callback booléen');

file_put_contents($limitsRoot . '/templates/limits.ui', $limitsTemplate);
file_put_contents($limitsRoot . '/src/app.php', $limitsApp);

$limitsC = (new PhpCompiler($limitsRoot))->compile($limitsRoot . '/src/app.php', null, 'c');
$limitsCSource = (string) file_get_contents($limitsC['cPath']);
T::ok(str_contains($limitsCSource, 'cy_ui_scrolled'), 'limites : scrolled natif');
T::ok(str_contains($limitsCSource, 'cy_ui_image'), 'limites : image native');
T::ok(str_contains($limitsCSource, 'cy_ui_set_name'), 'limites : nom natif');
T::ok(str_contains($limitsCSource, 'cy_ui_set_size'), 'limites : taille native');
T::ok(str_contains($limitsCSource, 'cy_ui_add_css'), 'limites : CSS natif');

$limitsFacade = <<<'PHP'
<?php
$app = new Application('com.cyonima.ui.limits.facade');
$entry = new Entry('Nom');
$entry->setText('Cyonima');
$entry->activate();
$image = new Image('packaging/icon/com.cyonima.Ide.svg');
$image->setSize(48, 48);
$image->setName('logo');
$scrolled = new ScrolledWindow();
$box = new Box(true, 0);
$scrolled->setChild($box);
$scrolled->setSize(-1, 120);
PHP;

$limitsFacadeC = $compilePhp('ui-limits-facade', $limitsFacade, 'c');
$limitsFacadeSource = (string) file_get_contents($limitsFacadeC['cPath']);
T::ok(str_contains($limitsFacadeSource, 'cy_ui_entry_set_text'), 'limites : Entry::setText');
T::ok(str_contains($limitsFacadeSource, 'cy_ui_activate_entry'), 'limites : Entry::activate');
T::ok(str_contains($limitsFacadeSource, 'cy_ui_scrolled_set_child'), 'limites : ScrolledWindow');
T::ok(str_contains($limitsFacadeSource, 'cy_ui_set_size'), 'limites : Widget::setSize');

$limitsErrorApp = <<<'PHP'
<?php
namespace App;

use Application;
use Cyonima\Templates\Limits;

$onToggle = function (int $code): void {
    echo $code, "\n";
};

$application = new Application('com.cyonima.ui.limits.error');
$window = Limits::build(true, $onToggle);
PHP;

file_put_contents($limitsRoot . '/src/error.php', $limitsErrorApp);

try {
    (new PhpCompiler($limitsRoot))->compile($limitsRoot . '/src/error.php', null, 'c');
    T::ok(false, 'limites : callback booléen incompatible');
} catch (Cyonima\Compiler\CompilerException $exception) {
    T::ok(
        str_contains($exception->getMessage(), 'callable(bool):void'),
        'limites : callback booléen incompatible',
        $exception->getMessage(),
    );
}

$limitsErrors = [
    ['image largeur invalide', '<window><image width="large" /></window>', 'entier attendu pour "width"'],
    ['scrolled hauteur invalide', '<window><scrolled height="haut"></scrolled></window>', 'entier attendu pour "height"'],
];

foreach ($limitsErrors as $index => [$label, $source, $needle]) {
    try {
        (new TemplateCompiler('LimitsBroken' . $index))->compile($source);
        T::ok(false, 'limites : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'limites : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

$limitsGtk = trim((string) shell_exec('pkg-config --exists gtk4 2>/dev/null && echo yes || true')) === 'yes';
$limitsDisplay = getenv('DISPLAY') !== false && getenv('DISPLAY') !== '';

if (!$hasGcc || !$limitsGtk) {
    T::skip('GTK4 dev absent : exécution des limites UI non testée');
} elseif (!$limitsDisplay) {
    T::skip('DISPLAY absent : exécution des limites UI non testée');
} else {
    $limitsElf = (new PhpCompiler($limitsRoot))->compile($limitsRoot . '/src/app.php', null, 'elf');
    $limitsRunner = 'timeout 8 ' . escapeshellarg((string) $limitsElf['binaryPath']);

    if (getenv('DBUS_SESSION_BUS_ADDRESS') === false
        && trim((string) shell_exec('command -v dbus-run-session 2>/dev/null')) !== '') {
        $limitsRunner = 'dbus-run-session -- ' . $limitsRunner;
    }

    $limitsOut = [];
    $limitsStatus = 0;
    exec($limitsRunner . ' 2>&1', $limitsOut, $limitsStatus);
    T::ok(
        $limitsStatus === 0,
        'limites : template avec style, image et scroll exécuté',
        implode("\n", $limitsOut),
    );

    $stateSource = <<<'PHP'
<?php
$app = new Application('com.cyonima.ui.limits.state');
$check = new CheckButton('Basculer', false);
$events = '';

$check->onToggled(function (bool $active) use (&$events): void {
    if ($active) {
        $events = $events . '1';
    } else {
        $events = $events . '0';
    }
});

$check->setActive(true);
$check->setActive(false);

if ($events === '10') {
    echo "toggled=ok\n";
}

$entry = new Entry('texte');
$received = '';
$entry->onActivate(function (string $value) use (&$received): void {
    $received = $value;
});
$entry->setText('bonjour');
$entry->activate();

if ($received === 'bonjour') {
    echo "entry=ok\n";
}

$box = new Box(true, 0);
$lastHandle = 0;

for ($i = 0; $i < 300; $i = $i + 1) {
    $label = new Label('x');
    $box->append($label);
    $lastHandle = $label->handle;
}

if ($lastHandle > 300) {
    echo "growth=ok\n";
}

$image = new Image('packaging/icon/com.cyonima.Ide.svg');
$scrolled = new ScrolledWindow();
$scrolled->setChild($box);
$scrolled->setSize(-1, 100);
$image->setSize(32, 32);
$image->setName('logo');
echo 'handles=', $image->handle, ',', $scrolled->handle, "\n";
PHP;

    $stateElf = $compilePhp('ui-limits-state', $stateSource, 'elf');
    $stateRunner = 'timeout 8 ' . escapeshellarg((string) $stateElf['binaryPath']);

    if (getenv('DBUS_SESSION_BUS_ADDRESS') === false
        && trim((string) shell_exec('command -v dbus-run-session 2>/dev/null')) !== '') {
        $stateRunner = 'dbus-run-session -- ' . $stateRunner;
    }

    $stateOut = [];
    $stateStatus = 0;
    exec($stateRunner . ' 2>&1', $stateOut, $stateStatus);
    $stateText = implode("\n", $stateOut);
    T::ok(str_contains($stateText, 'toggled=ok'), 'limites : callback booléen à l’exécution', $stateText);
    T::ok(str_contains($stateText, 'entry=ok'), 'limites : callback texte à l’exécution', $stateText);
    T::ok(str_contains($stateText, 'growth=ok'), 'limites : plus de 256 widgets', $stateText);
    T::ok($stateStatus === 0, 'limites : état des widgets exécuté', $stateText);
}

$removeTree($limitsRoot);

// ---------------------------------------------------------------------------
T::section('45. Templates — fragments, includes et paramètres');

$fragRoot = sys_get_temp_dir() . '/cyonima_fragments_' . getmypid();
$removeTree($fragRoot);
mkdir($fragRoot . '/src', 0775, true);
mkdir($fragRoot . '/templates/partials', 0775, true);

$cardSource = <<<'UI'
@params(App\Todo $todo, callable(bool):void $toggle)

<style>
    .card { padding: 6px; }
</style>

<box vertical="true" spacing="4" class="card">
    <check-button label="{{ $todo->title }}" active="{{ $todo->done }}" on-toggled="{{ $toggle }}" />
    <label>{{ $todo->status }}</label>
</box>
UI;

$boardSource = <<<'UI'
@params(array<App\Todo> $todos, string $title, callable(bool):void $toggle)

<window title="{{ $title }}" width="420" height="480">
    <box vertical="true" spacing="6">
        @foreach ($todos as $todo)
            <include file="partials/card.ui" todo="{{ $todo }}" toggle="{{ $toggle }}" />
        @endforeach
    </box>
</window>
UI;

$boardApp = <<<'PHP'
<?php
namespace App;

use Application;
use Cyonima\Templates\Board;

final class Todo
{
    public string $title;
    public string $status;
    public bool $done;

    public function __construct(string $title, string $status, bool $done)
    {
        $this->title = $title;
        $this->status = $status;
        $this->done = $done;
    }
}

$todos = [];
$todos[] = new Todo('Fragments', 'en cours', false);
$todos[] = new Todo('Includes', 'fait', true);

$toggle = function (bool $active): void {
    echo "toggle\n";
};

$application = new Application('com.cyonima.fragments.board');
$window = Board::build($todos, 'Tableau', $toggle);
$window->present();
$application->after(60, function () use ($application): void {
    $application->quit();
});
$application->run();
PHP;

file_put_contents($fragRoot . '/templates/partials/card.ui', $cardSource);
file_put_contents($fragRoot . '/templates/board.ui', $boardSource);
file_put_contents($fragRoot . '/src/app.php', $boardApp);

$fragmentGenerated = (new TemplateCompiler('Card'))->compile($cardSource);
T::ok(str_contains($fragmentGenerated, '@return Box'), 'fragment : retour Box');
T::ok(str_contains($fragmentGenerated, '$root = new Box(true, 0);'), 'fragment : racine Box');
T::ok(str_contains($fragmentGenerated, 'return $root;'), 'fragment : aucun Window');
T::ok(str_contains($fragmentGenerated, 'ui_add_css('), 'fragment : style local');

$fragC = (new PhpCompiler($fragRoot))->compile($fragRoot . '/src/app.php', null, 'c');
$fragCSource = (string) file_get_contents($fragC['cPath']);
T::ok(
    str_contains($fragCSource, 'CyObj_Cyonima__Templates__Card'),
    'fragment : classe Card générée',
);
T::ok(
    str_contains($fragCSource, 'fn_Cyonima__Templates__Card__build'),
    'fragment : build Card',
);
T::ok(
    str_contains($fragCSource, 'fn_Cyonima__Templates__Board__build')
        && str_contains($fragCSource, 'fn_Cyonima__Templates__Card__build('),
    'fragment : include compilé dans Board',
);

$exampleFragC = (new PhpCompiler(
    $fragRoot,
    [],
    [],
    [dirname(__DIR__) . '/examples/native-todo-list.ui'],
))->compile(
    dirname(__DIR__) . '/examples/native-templates.php',
    null,
    'c',
);
$exampleFragSource = (string) file_get_contents($exampleFragC['cPath']);
T::ok(
    str_contains($exampleFragSource, 'CyObj_Cyonima__Templates__TodoRow'),
    'fragment : include découvert à la demande',
);

$fragResolver = function (string $path) use ($cardSource): array {
    return [
        'class' => 'Card',
        'params' => TemplateCompiler::parameterDeclarations($cardSource),
    ];
};

$fragErrorPage = static fn (string $include): string => <<<UI
<window title="Erreur">
    <box vertical="true">
        $include
    </box>
</window>
UI;

file_put_contents(
    $fragRoot . '/templates/missing.ui',
    $fragErrorPage('<include file="partials/card.ui" todo="{{ $todo }}" />'),
);
file_put_contents(
    $fragRoot . '/templates/unknown.ui',
    $fragErrorPage('<include file="partials/card.ui" todo="{{ $todo }}" toggle="{{ $toggle }}" extra="x" />'),
);
file_put_contents(
    $fragRoot . '/templates/static.ui',
    $fragErrorPage('<include file="partials/card.ui" todo="littéral" toggle="{{ $toggle }}" />'),
);

$fragErrorCases = [
    [
        'paramètre manquant',
        $fragRoot . '/templates/missing.ui',
        'paramètre "toggle" manquant',
    ],
    [
        'paramètre inconnu',
        $fragRoot . '/templates/unknown.ui',
        'paramètre inconnu "extra"',
    ],
    [
        'paramètre typé statique',
        $fragRoot . '/templates/static.ui',
        'doit être {{ expression }}',
    ],
];

foreach ($fragErrorCases as $index => [$label, $pagePath, $needle]) {
    $pageSource = (string) file_get_contents($pagePath);

    try {
        (new TemplateCompiler('FragError' . $index, $pagePath, $fragResolver))
            ->compile($pageSource);
        T::ok(false, 'fragment : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'fragment : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

file_put_contents(
    $fragRoot . '/templates/self.ui',
    '<include file="self.ui" />',
);
$selfSource = (string) file_get_contents($fragRoot . '/templates/self.ui');

try {
    (new TemplateCompiler(
        'Self',
        $fragRoot . '/templates/self.ui',
        static fn (string $path): array => ['class' => 'Self', 'params' => []],
    ))->compile($selfSource);
    T::ok(false, 'fragment : include récursif refusé');
} catch (Cyonima\Compiler\CompilerException $exception) {
    T::ok(
        str_contains($exception->getMessage(), 'include récursif'),
        'fragment : include récursif refusé',
        $exception->getMessage(),
    );
}

// Ces templates sont volontairement invalides : ils ne doivent pas être
// découverts par la compilation GTK de la page valide.
unlink($fragRoot . '/templates/missing.ui');
unlink($fragRoot . '/templates/unknown.ui');
unlink($fragRoot . '/templates/static.ui');
unlink($fragRoot . '/templates/self.ui');

$fragCleanC = (new PhpCompiler($fragRoot))->compile(
    $fragRoot . '/src/app.php',
    null,
    'c',
);
$fragCleanSource = (string) file_get_contents($fragCleanC['cPath']);
T::ok(
    str_contains($fragCleanSource, 'CyObj_Cyonima__Templates__Board'),
    'fragment : compilation propre après les erreurs',
);

$nestedPage = <<<'UI'
@params(string $title)

<window title="{{ $title }}" width="320" height="200">
    <box vertical="true">
        <include file="nested.ui" />
    </box>
</window>
UI;

$nestedInner = <<<'UI'
@params()

<window title="Interdit" width="200" height="100">
    <box vertical="true">
        <label>Page</label>
    </box>
</window>
UI;

$pageRoot = $fragRoot . '/page-project';
mkdir($pageRoot . '/templates', 0775, true);
mkdir($pageRoot . '/src', 0775, true);
file_put_contents($pageRoot . '/templates/parent-page.ui', $nestedPage);
file_put_contents($pageRoot . '/templates/nested.ui', $nestedInner);
file_put_contents($pageRoot . '/src/nested.php', <<<'PHP'
<?php
namespace App;

use Cyonima\Templates\ParentPage;

$window = ParentPage::build('Parent');
PHP);

try {
    (new PhpCompiler($pageRoot))->compile($pageRoot . '/src/nested.php', null, 'c');
    T::ok(false, 'fragment : page incluse refusée');
} catch (Cyonima\Compiler\CompilerException $exception) {
    T::ok(
        str_contains($exception->getMessage(), 'template de page ne peut pas être inclus'),
        'fragment : page incluse refusée',
        $exception->getMessage(),
    );
}

$fragGtk = trim((string) shell_exec('pkg-config --exists gtk4 2>/dev/null && echo yes || true')) === 'yes';
$fragDisplay = getenv('DISPLAY') !== false && getenv('DISPLAY') !== '';

if (!$hasGcc || !$fragGtk) {
    T::skip('GTK4 dev absent : exécution des fragments non testée');
} elseif (!$fragDisplay) {
    T::skip('DISPLAY absent : exécution des fragments non testée');
} else {
    $fragElf = (new PhpCompiler($fragRoot))->compile($fragRoot . '/src/app.php', null, 'elf');
    $fragRunner = 'timeout 8 ' . escapeshellarg((string) $fragElf['binaryPath']);

    if (getenv('DBUS_SESSION_BUS_ADDRESS') === false
        && trim((string) shell_exec('command -v dbus-run-session 2>/dev/null')) !== '') {
        $fragRunner = 'dbus-run-session -- ' . $fragRunner;
    }

    $fragOut = [];
    $fragStatus = 0;
    exec($fragRunner . ' 2>&1', $fragOut, $fragStatus);
    T::ok(
        $fragStatus === 0,
        'fragment : page avec includes exécutée',
        implode("\n", $fragOut),
    );
}

$removeTree($fragRoot);

// ---------------------------------------------------------------------------
T::section('46. UI — listes GTK, DropDown et modèles de chaînes');

$listsRoot = sys_get_temp_dir() . '/cyonima_lists_' . getmypid();
$removeTree($listsRoot);
mkdir($listsRoot . '/src', 0775, true);
mkdir($listsRoot . '/templates', 0775, true);

$listsTemplate = <<<'UI'
@params(array<string> $options, int $selected, callable(int):void $onSelect)

<window title="Listes" width="420" height="480">
    <box vertical="true" spacing="8">
        <drop-down options="{{ $options }}" selected="{{ $selected }}" on-change="{{ $onSelect }}" />
        <drop-down options="Rouge,Vert,Bleu" selected="2" />
        <list-box selection="single" on-row-activated="{{ $onSelect }}">
            @foreach ($options as $option)
                <label>{{ $option }}</label>
            @endforeach
        </list-box>
    </box>
</window>
UI;

$listsApp = <<<'PHP'
<?php
namespace App;

use Application;
use Cyonima\Templates\Lists;

$options = ['Un', 'Deux', 'Trois'];
$onSelect = function (int $index): void {
    echo 'select=', $index, "\n";
};

$application = new Application('com.cyonima.lists.page');
$window = Lists::build($options, 1, $onSelect);
$window->present();
$application->after(60, function () use ($application): void {
    $application->quit();
});
$application->run();
PHP;

$listsGenerated = (new TemplateCompiler('Lists'))->compile($listsTemplate);
T::ok(str_contains($listsGenerated, 'new DropDown('), 'listes : DropDown généré');
T::ok(str_contains($listsGenerated, 'new StringList('), 'listes : StringList généré');
T::ok(str_contains($listsGenerated, 'new ListBox()'), 'listes : ListBox généré');
T::ok(str_contains($listsGenerated, "->setSelectionMode('single')"), 'listes : mode de sélection');
T::ok(str_contains($listsGenerated, '->onChanged(($onSelect))'), 'listes : callback drop-down');
T::ok(str_contains($listsGenerated, '->onRowActivated(($onSelect))'), 'listes : callback de ligne');

file_put_contents($listsRoot . '/templates/lists.ui', $listsTemplate);
file_put_contents($listsRoot . '/src/app.php', $listsApp);

$listsC = (new PhpCompiler($listsRoot))->compile($listsRoot . '/src/app.php', null, 'c');
$listsCSource = (string) file_get_contents($listsC['cPath']);
T::ok(str_contains($listsCSource, 'cy_ui_string_list'), 'listes : modèle natif');
T::ok(str_contains($listsCSource, 'cy_ui_drop_down'), 'listes : drop-down natif');
T::ok(str_contains($listsCSource, 'cy_ui_list_box'), 'listes : list-box natif');
T::ok(str_contains($listsCSource, 'cy_ui_on_row_activated_closure'), 'listes : signal row-activated');
T::ok(str_contains($listsCSource, 'cy_ui_on_drop_down_change_closure'), 'listes : signal selected');

$listsErrors = [
    ['drop-down sans source', '<window><drop-down /></window>', 'exige soit "options", soit "model"'],
    [
        'drop-down double source',
        '<window><drop-down options="a,b" model="{{ $model }}" /></window>',
        'exige soit "options", soit "model"',
    ],
    ['drop-down options vide', '<window><drop-down options="" /></window>', '"options" ne peut pas être vide'],
    [
        'list-box sélection invalide',
        '<window><list-box selection="double"></list-box></window>',
        '"selection" doit valoir single, none ou multiple',
    ],
];

foreach ($listsErrors as $index => [$label, $source, $needle]) {
    try {
        (new TemplateCompiler('ListsBroken' . $index))->compile($source);
        T::ok(false, 'listes : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'listes : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

$listsCallbackError = <<<'PHP'
<?php
namespace App;

use Application;
use Cyonima\Templates\Lists;

$options = ['Un'];
$onSelect = function (string $text): void {
    echo $text, "\n";
};

$application = new Application('com.cyonima.lists.error');
$window = Lists::build($options, 0, $onSelect);
PHP;

file_put_contents($listsRoot . '/src/error.php', $listsCallbackError);

try {
    (new PhpCompiler($listsRoot))->compile($listsRoot . '/src/error.php', null, 'c');
    T::ok(false, 'listes : callback de ligne incompatible');
} catch (Cyonima\Compiler\CompilerException $exception) {
    T::ok(
        str_contains($exception->getMessage(), 'callable():void ou callable(int):void')
            || str_contains($exception->getMessage(), 'callable(int):void'),
        'listes : callback de ligne incompatible',
        $exception->getMessage(),
    );
}

$listsValues = <<<'PHP'
<?php

$app = new Application('com.cyonima.lists.values');
$model = new StringList(['a', 'b', 'c']);

if ($model->count() === 3 && $model->item(1) === 'b') {
    echo "list=ok\n";
}

$drop = new DropDown($model, 1);

if ($drop->selectedIndex() === 1 && $drop->selectedText() === 'b') {
    echo "drop=ok\n";
}

$seenInt = -1;
$drop->onChanged(function (int $index) use (&$seenInt): void {
    $seenInt = $index;
});
$drop->setSelected(2);

if ($seenInt === 2) {
    echo "change-int=ok\n";
}

$dropText = new DropDown($model, 0);
$seenText = '';
$dropText->onChanged(function (string $text) use (&$seenText): void {
    $seenText = $text;
});
$dropText->setSelected(2);

if ($seenText === 'c') {
    echo "change-text=ok\n";
}

$list = new ListBox();
$list->append(new Label('x'));
$list->append(new Label('y'));
$list->selectIndex(1);

if ($list->selectedIndex() === 1) {
    echo "list-select=ok\n";
}

$activated = -1;
$list->onRowActivated(function (int $index) use (&$activated): void {
    $activated = $index;
});
$list->activate(1);

if ($activated === 1) {
    echo "row-activated=ok\n";
}
PHP;

$listsValuesC = $compilePhp('ui-lists-values', $listsValues, 'c');
$listsValuesSource = (string) file_get_contents($listsValuesC['cPath']);
T::ok(str_contains($listsValuesSource, 'cy_ui_string_list'), 'listes : façade StringList');
T::ok(str_contains($listsValuesSource, 'cy_ui_list_box_activate'), 'listes : activation de ligne');

$exampleListsC = (new PhpCompiler(
    $listsRoot,
    [],
    [],
    [dirname(__DIR__) . '/examples/native-lists.ui'],
))->compile(
    dirname(__DIR__) . '/examples/native-lists.php',
    null,
    'c',
);
$exampleListsSource = (string) file_get_contents($exampleListsC['cPath']);
T::ok(
    str_contains($exampleListsSource, 'cy_ui_drop_down')
        && str_contains($exampleListsSource, 'cy_ui_list_box'),
    'listes : exemple du dépôt',
);

$listsGtk = trim((string) shell_exec('pkg-config --exists gtk4 2>/dev/null && echo yes || true')) === 'yes';
$listsDisplay = getenv('DISPLAY') !== false && getenv('DISPLAY') !== '';

if (!$hasGcc || !$listsGtk) {
    T::skip('GTK4 dev absent : exécution des listes non testée');
} elseif (!$listsDisplay) {
    T::skip('DISPLAY absent : exécution des listes non testée');
} else {
    $listsElf = (new PhpCompiler($listsRoot))->compile($listsRoot . '/src/app.php', null, 'elf');
    $listsRunner = 'timeout 8 ' . escapeshellarg((string) $listsElf['binaryPath']);

    if (getenv('DBUS_SESSION_BUS_ADDRESS') === false
        && trim((string) shell_exec('command -v dbus-run-session 2>/dev/null')) !== '') {
        $listsRunner = 'dbus-run-session -- ' . $listsRunner;
    }

    $listsOut = [];
    $listsStatus = 0;
    exec($listsRunner . ' 2>&1', $listsOut, $listsStatus);
    T::ok(
        $listsStatus === 0,
        'listes : page avec DropDown et ListBox exécutée',
        implode("\n", $listsOut),
    );

    $valuesElf = $compilePhp('ui-lists-values', $listsValues, 'elf');
    $valuesRunner = 'timeout 8 ' . escapeshellarg((string) $valuesElf['binaryPath']);

    if (getenv('DBUS_SESSION_BUS_ADDRESS') === false
        && trim((string) shell_exec('command -v dbus-run-session 2>/dev/null')) !== '') {
        $valuesRunner = 'dbus-run-session -- ' . $valuesRunner;
    }

    $valuesOut = [];
    $valuesStatus = 0;
    exec($valuesRunner . ' 2>&1', $valuesOut, $valuesStatus);
    $valuesText = implode("\n", $valuesOut);
    T::ok(str_contains($valuesText, 'list=ok'), 'listes : StringList à l’exécution', $valuesText);
    T::ok(str_contains($valuesText, 'drop=ok'), 'listes : DropDown à l’exécution', $valuesText);
    T::ok(str_contains($valuesText, 'change-int=ok'), 'listes : callback index', $valuesText);
    T::ok(str_contains($valuesText, 'change-text=ok'), 'listes : callback texte', $valuesText);
    T::ok(str_contains($valuesText, 'list-select=ok'), 'listes : sélection de ligne', $valuesText);
    T::ok(str_contains($valuesText, 'row-activated=ok'), 'listes : activation de ligne', $valuesText);
    T::ok($valuesStatus === 0, 'listes : valeurs exécutées', $valuesText);
}

$removeTree($listsRoot);

// ---------------------------------------------------------------------------
T::section('47. UI — modèle métier typé et Listable');

$typedRoot = sys_get_temp_dir() . '/cyonima_typed_' . getmypid();
$removeTree($typedRoot);
mkdir($typedRoot . '/src', 0775, true);
mkdir($typedRoot . '/templates', 0775, true);

$typedTemplate = <<<'UI'
@params(ObjectList $objects, int $selected, callable(int):void $onSelect)

<window title="Objets" width="420" height="480">
    <box vertical="true" spacing="8">
        <drop-down items="{{ $objects }}" selected="{{ $selected }}" on-change="{{ $onSelect }}" />
        <list-box items="{{ $objects }}" selection="single" on-row-activated="{{ $onSelect }}" />
    </box>
</window>
UI;

$typedApp = <<<'PHP'
<?php
namespace App;

use Application;
use Cyonima\Templates\TypedObjects;
use Listable;
use ObjectList;

final class Todo implements Listable
{
    public string $title;

    public function __construct(string $title)
    {
        $this->title = $title;
    }

    public function listLabel(): string
    {
        return $this->title;
    }
}

$application = new Application('com.cyonima.typed.page');

$todos = [];
$todos[] = new Todo('Un');
$todos[] = new Todo('Deux');
$todos[] = new Todo('Trois');
$objects = new ObjectList($todos);

$onSelect = function (int $index): void {
    echo 'select=', $index, "\n";
};

$window = TypedObjects::build($objects, 1, $onSelect);
$window->present();
$application->after(60, function () use ($application): void {
    $application->quit();
});
$application->run();
PHP;

$typedGenerated = (new TemplateCompiler('TypedObjects'))->compile($typedTemplate);
T::ok(str_contains($typedGenerated, 'DropDown::fromObjects(($objects)'), 'typed : DropDown depuis objets');
T::ok(str_contains($typedGenerated, '->appendObjects(($objects))'), 'typed : ListBox depuis objets');

file_put_contents($typedRoot . '/templates/typed-objects.ui', $typedTemplate);
file_put_contents($typedRoot . '/src/app.php', $typedApp);

$typedC = (new PhpCompiler($typedRoot))->compile($typedRoot . '/src/app.php', null, 'c');
$typedCSource = (string) file_get_contents($typedC['cPath']);
T::ok(str_contains($typedCSource, 'CyObj_ObjectList'), 'typed : ObjectList C');
T::ok(str_contains($typedCSource, 'CyVTable_Listable'), 'typed : interface Listable C');
T::ok(str_contains($typedCSource, 'fn_DropDown__fromObjects'), 'typed : fabrique DropDown');
T::ok(str_contains($typedCSource, 'fn_ListBox__appendObjects'), 'typed : appendObjects');

$typedErrors = [
    [
        'drop-down items statique',
        '<window><drop-down items="x" /></window>',
        '"items" doit être {{ expression }}',
    ],
    [
        'drop-down items et options',
        '<window><drop-down items="{{ $objects }}" options="a,b" /></window>',
        'exige soit "options", soit "model", soit "items"',
    ],
    [
        'list-box items statique',
        '<window><list-box items="x"></list-box></window>',
        '"items" doit être {{ expression }}',
    ],
];

foreach ($typedErrors as $index => [$label, $source, $needle]) {
    try {
        (new TemplateCompiler('TypedBroken' . $index))->compile($source);
        T::ok(false, 'typed : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'typed : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

$typedValues = <<<'PHP'
<?php
namespace App;

use Application;
use DropDown;
use ListBox;
use Listable;
use ObjectList;

final class Item implements Listable
{
    public string $label;

    public function __construct(string $label)
    {
        $this->label = $label;
    }

    public function listLabel(): string
    {
        return $this->label;
    }
}

$application = new Application('com.cyonima.typed.values');

$items = [];
$items[] = new Item('Un');
$items[] = new Item('Deux');
$items[] = new Item('Trois');
$objects = new ObjectList($items);
$labels = $objects->labels();

if ($objects->count() === 3 && $labels[1] === 'Deux') {
    echo "labels=ok\n";
}

$drop = DropDown::fromObjects($objects, 1);
$selected = $drop->selectedObject();

if ($selected !== null && $selected->listLabel() === 'Deux') {
    echo "selected=ok\n";
}

$list = new ListBox();
$list->appendObjects($objects);
$list->selectIndex(2);

if ($list->selectedIndex() === 2) {
    echo "rows=ok\n";
}

$drop->setSelected(2);
$changed = $drop->selectedObject();

if ($changed !== null && $changed->listLabel() === 'Trois') {
    echo "change=ok\n";
}
PHP;

$typedValuesC = $compilePhp('ui-typed-values', $typedValues, 'c');
$typedValuesSource = (string) file_get_contents($typedValuesC['cPath']);
T::ok(str_contains($typedValuesSource, 'cy_ui_drop_down'), 'typed : façade DropDown');
T::ok(str_contains($typedValuesSource, 'cy_ui_list_box'), 'typed : façade ListBox');

$typedGtk = trim((string) shell_exec('pkg-config --exists gtk4 2>/dev/null && echo yes || true')) === 'yes';
$typedDisplay = getenv('DISPLAY') !== false && getenv('DISPLAY') !== '';

if (!$hasGcc || !$typedGtk) {
    T::skip('GTK4 dev absent : exécution du modèle typé non testée');
} elseif (!$typedDisplay) {
    T::skip('DISPLAY absent : exécution du modèle typé non testée');
} else {
    $typedElf = (new PhpCompiler($typedRoot))->compile($typedRoot . '/src/app.php', null, 'elf');
    $typedRunner = 'timeout 8 ' . escapeshellarg((string) $typedElf['binaryPath']);

    if (getenv('DBUS_SESSION_BUS_ADDRESS') === false
        && trim((string) shell_exec('command -v dbus-run-session 2>/dev/null')) !== '') {
        $typedRunner = 'dbus-run-session -- ' . $typedRunner;
    }

    $typedOut = [];
    $typedStatus = 0;
    exec($typedRunner . ' 2>&1', $typedOut, $typedStatus);
    T::ok(
        $typedStatus === 0,
        'typed : page avec modèle métier exécutée',
        implode("\n", $typedOut),
    );

    $valuesElf = $compilePhp('ui-typed-values', $typedValues, 'elf');
    $valuesRunner = 'timeout 8 ' . escapeshellarg((string) $valuesElf['binaryPath']);

    if (getenv('DBUS_SESSION_BUS_ADDRESS') === false
        && trim((string) shell_exec('command -v dbus-run-session 2>/dev/null')) !== '') {
        $valuesRunner = 'dbus-run-session -- ' . $valuesRunner;
    }

    $valuesOut = [];
    $valuesStatus = 0;
    exec($valuesRunner . ' 2>&1', $valuesOut, $valuesStatus);
    $valuesText = implode("\n", $valuesOut);
    T::ok(str_contains($valuesText, 'labels=ok'), 'typed : labels métier', $valuesText);
    T::ok(str_contains($valuesText, 'selected=ok'), 'typed : sélection d’objet', $valuesText);
    T::ok(str_contains($valuesText, 'rows=ok'), 'typed : lignes typées', $valuesText);
    T::ok(str_contains($valuesText, 'change=ok'), 'typed : changement d’objet', $valuesText);
    T::ok($valuesStatus === 0, 'typed : valeurs exécutées', $valuesText);
}

$exampleTypedC = (new PhpCompiler(
    $typedRoot,
    [],
    [],
    [dirname(__DIR__) . '/examples/native-typed-lists.ui'],
))->compile(
    dirname(__DIR__) . '/examples/native-typed-lists.php',
    null,
    'c',
);
$exampleTypedSource = (string) file_get_contents($exampleTypedC['cPath']);
T::ok(
    str_contains($exampleTypedSource, 'fn_DropDown__fromObjects')
        && str_contains($exampleTypedSource, 'fn_ListBox__appendObjects'),
    'typed : exemple du dépôt',
);

$removeTree($typedRoot);

// ---------------------------------------------------------------------------
T::section('48. UI — menus popover GTK');

$menuRoot = sys_get_temp_dir() . '/cyonima_menu_' . getmypid();
$removeTree($menuRoot);
mkdir($menuRoot . '/src', 0775, true);
mkdir($menuRoot . '/templates', 0775, true);

$menuTemplate = <<<'UI'
@params(string $title, callable():void $onOpen, callable():void $onQuit)

<window title="{{ $title }}" width="420" height="320">
    <box vertical="true" spacing="8">
        <menu-button label="{{ $title }}" class="menu">
            <button text="Ouvrir" on-click="{{ $onOpen }}" />
            <separator />
            <button text="Quitter" on-click="{{ $onQuit }}" />
        </menu-button>
        <label>Contenu</label>
    </box>
</window>
UI;

$menuApp = <<<'PHP'
<?php
namespace App;

use Application;
use Cyonima\Templates\MenuPage;

$onOpen = function (): void {
    echo "open\n";
};
$onQuit = function (): void {
    echo "quit\n";
};

$application = new Application('com.cyonima.menu.page');
$window = MenuPage::build('Actions', $onOpen, $onQuit);
$window->present();
$application->after(60, function () use ($application): void {
    $application->quit();
});
$application->run();
PHP;

$menuGenerated = (new TemplateCompiler('MenuPage'))->compile($menuTemplate);
T::ok(str_contains($menuGenerated, 'new MenuButton(($title))'), 'menu : MenuButton généré');
T::ok(str_contains($menuGenerated, 'new Popover()'), 'menu : Popover généré');
T::ok(str_contains($menuGenerated, '->setPopover('), 'menu : popover attaché');
T::ok(str_contains($menuGenerated, '->setChild('), 'menu : contenu du popover');
T::ok(str_contains($menuGenerated, "->addClass('menu')"), 'menu : classe CSS');

file_put_contents($menuRoot . '/templates/menu-page.ui', $menuTemplate);
file_put_contents($menuRoot . '/src/app.php', $menuApp);

$menuC = (new PhpCompiler($menuRoot))->compile($menuRoot . '/src/app.php', null, 'c');
$menuCSource = (string) file_get_contents($menuC['cPath']);
T::ok(str_contains($menuCSource, 'cy_ui_menu_button'), 'menu : bouton natif');
T::ok(str_contains($menuCSource, 'cy_ui_popover'), 'menu : popover natif');
T::ok(str_contains($menuCSource, 'cy_ui_popover_set_child'), 'menu : contenu natif');
T::ok(str_contains($menuCSource, 'cy_ui_menu_button_set_popover'), 'menu : attachement natif');

try {
    (new TemplateCompiler('MenuBroken'))->compile(
        '<window><menu-button spacing="large"><label>x</label></menu-button></window>',
    );
    T::ok(false, 'menu : erreur espacement invalide');
} catch (Cyonima\Compiler\CompilerException $exception) {
    T::ok(
        str_contains($exception->getMessage(), 'entier attendu pour "spacing"'),
        'menu : erreur espacement invalide',
        $exception->getMessage(),
    );
}

$menuValues = <<<'PHP'
<?php
$app = new Application('com.cyonima.menu.values');
$menu = new MenuButton('Menu');
$popover = new Popover();
$box = new Box(true, 4);
$action = new Button('Action');
$count = 0;

$action->onClicked(function () use (&$count): void {
    $count = $count + 1;
});

$box->append($action);
$popover->setChild($box);
$menu->setPopover($popover);
$menu->setLabel('Actions');
$action->click();

if ($count === 1) {
    echo "click=ok\n";
}

echo "menu=ok\n";
PHP;

$menuValuesC = $compilePhp('ui-menu-values', $menuValues, 'c');
$menuValuesSource = (string) file_get_contents($menuValuesC['cPath']);
T::ok(str_contains($menuValuesSource, 'cy_ui_menu_button'), 'menu : façade MenuButton');
T::ok(str_contains($menuValuesSource, 'cy_ui_popover_set_child'), 'menu : façade Popover');

$menuGtk = trim((string) shell_exec('pkg-config --exists gtk4 2>/dev/null && echo yes || true')) === 'yes';
$menuDisplay = getenv('DISPLAY') !== false && getenv('DISPLAY') !== '';

if (!$hasGcc || !$menuGtk) {
    T::skip('GTK4 dev absent : exécution des menus non testée');
} elseif (!$menuDisplay) {
    T::skip('DISPLAY absent : exécution des menus non testée');
} else {
    $menuElf = (new PhpCompiler($menuRoot))->compile($menuRoot . '/src/app.php', null, 'elf');
    $menuRunner = 'timeout 8 ' . escapeshellarg((string) $menuElf['binaryPath']);

    if (getenv('DBUS_SESSION_BUS_ADDRESS') === false
        && trim((string) shell_exec('command -v dbus-run-session 2>/dev/null')) !== '') {
        $menuRunner = 'dbus-run-session -- ' . $menuRunner;
    }

    $menuOut = [];
    $menuStatus = 0;
    exec($menuRunner . ' 2>&1', $menuOut, $menuStatus);
    T::ok(
        $menuStatus === 0,
        'menu : page avec menu-button exécutée',
        implode("\n", $menuOut),
    );

    $valuesElf = $compilePhp('ui-menu-values', $menuValues, 'elf');
    $valuesRunner = 'timeout 8 ' . escapeshellarg((string) $valuesElf['binaryPath']);

    if (getenv('DBUS_SESSION_BUS_ADDRESS') === false
        && trim((string) shell_exec('command -v dbus-run-session 2>/dev/null')) !== '') {
        $valuesRunner = 'dbus-run-session -- ' . $valuesRunner;
    }

    $valuesOut = [];
    $valuesStatus = 0;
    exec($valuesRunner . ' 2>&1', $valuesOut, $valuesStatus);
    $valuesText = implode("\n", $valuesOut);
    T::ok(str_contains($valuesText, 'click=ok'), 'menu : action de menu', $valuesText);
    T::ok(str_contains($valuesText, 'menu=ok'), 'menu : valeurs exécutées', $valuesText);
    T::ok($valuesStatus === 0, 'menu : widget menu exécuté', $valuesText);
}

$exampleMenuC = (new PhpCompiler(
    $menuRoot,
    [],
    [],
    [dirname(__DIR__) . '/examples/native-menu.ui'],
))->compile(
    dirname(__DIR__) . '/examples/native-menu.php',
    null,
    'c',
);
$exampleMenuSource = (string) file_get_contents($exampleMenuC['cPath']);
T::ok(
    str_contains($exampleMenuSource, 'cy_ui_menu_button')
        && str_contains($exampleMenuSource, 'cy_ui_popover'),
    'menu : exemple du dépôt',
);

$removeTree($menuRoot);

// ---------------------------------------------------------------------------
T::section('49. UI — barre de menus native GMenu / GAction');

$menubarRoot = sys_get_temp_dir() . '/cyonima_menubar_' . getmypid();
$removeTree($menubarRoot);
mkdir($menubarRoot . '/src', 0775, true);
mkdir($menubarRoot . '/templates', 0775, true);

$menubarTemplate = <<<'UI'
@params(string $title, callable():void $onOpen, callable():void $onQuit, callable():void $onAbout)

<window title="{{ $title }}" width="460" height="320">
    <box vertical="true" spacing="8">
        <menu-bar>
            <menu label="Fichier">
                <item label="Ouvrir" on-click="{{ $onOpen }}" />
                <separator />
                <item label="Quitter" on-click="{{ $onQuit }}" />
            </menu>
            <menu label="Aide">
                <menu label="Plus">
                    <item label="À propos" on-click="{{ $onAbout }}" />
                </menu>
            </menu>
        </menu-bar>
        <label>Contenu</label>
    </box>
</window>
UI;

$menubarApp = <<<'PHP'
<?php
namespace App;

use Application;
use Cyonima\Templates\NativeMenuBarPage;

$onOpen = function (): void {
    echo "open\n";
};
$onQuit = function (): void {
    echo "quit\n";
};
$onAbout = function (): void {
    echo "about\n";
};

$application = new Application('com.cyonima.menubar.page');
$window = NativeMenuBarPage::build('Menus natifs', $onOpen, $onQuit, $onAbout);
$window->present();
$application->after(60, function () use ($application): void {
    $application->quit();
});
$application->run();
PHP;

$menubarGenerated = (new TemplateCompiler('NativeMenuBarPage'))->compile($menubarTemplate);
T::ok(str_contains($menubarGenerated, 'new MenuBar()'), 'menubar : MenuBar généré');
T::ok(str_contains($menubarGenerated, 'new Menu()'), 'menubar : Menu généré');
T::ok(str_contains($menubarGenerated, "->addStyledItem('Ouvrir'"), 'menubar : item généré');
T::ok(str_contains($menubarGenerated, '->addSeparator()'), 'menubar : séparateur généré');
T::ok(str_contains($menubarGenerated, "->add('Fichier'"), 'menubar : menu ajouté');
T::ok(str_contains($menubarGenerated, "->addSubmenu('Plus'"), 'menubar : sous-menu généré');

file_put_contents($menubarRoot . '/templates/native-menu-bar-page.ui', $menubarTemplate);
file_put_contents($menubarRoot . '/src/app.php', $menubarApp);

$menubarC = (new PhpCompiler($menubarRoot))->compile($menubarRoot . '/src/app.php', null, 'c');
$menubarCSource = (string) file_get_contents($menubarC['cPath']);
T::ok(str_contains($menubarCSource, 'cy_ui_menu_bar'), 'menubar : barre native');
T::ok(str_contains($menubarCSource, 'cy_ui_menu_add_item'), 'menubar : item natif');
T::ok(str_contains($menubarCSource, 'cy_ui_menu_add_separator'), 'menubar : séparateur natif');
T::ok(str_contains($menubarCSource, 'cy_ui_menu_add_submenu'), 'menubar : sous-menu natif');

$menubarErrors = [
    [
        'balise inattendue',
        '<window><menu-bar><label>x</label></menu-bar></window>',
        'balise <label> inattendue dans <menu-bar>',
    ],
    [
        'item sans callback',
        '<window><menu-bar><menu label="F"><item label="x" /></menu></menu-bar></window>',
        'on-click requis pour <item>',
    ],
    [
        'menu sans label',
        '<window><menu-bar><menu><item label="x" on-click="{{ $f }}" /></menu></menu-bar></window>',
        'label requis pour <menu>',
    ],
    [
        'directive dans menu',
        '<window><menu-bar>@if (true)<menu label="F"></menu>@endif</menu-bar></window>',
        'directive non supportée dans <menu-bar>',
    ],
];

foreach ($menubarErrors as $index => [$label, $source, $needle]) {
    try {
        (new TemplateCompiler('MenuBarBroken' . $index))->compile($source);
        T::ok(false, 'menubar : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'menubar : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

$menubarValues = <<<'PHP'
<?php
$app = new Application('com.cyonima.menubar.values');
$menu = new Menu();
$count = 0;
$menu->addItem('Action', function () use (&$count): void {
    $count = $count + 1;
});
$menu->addSeparator();

$sub = new Menu();
$sub->addItem('Sous-action', function () use (&$count): void {
    $count = $count + 10;
});
$menu->addSubmenu('Plus', $sub);
$menu->activate(0);

if ($count === 1) {
    echo "activate=ok\n";
}

$bar = new MenuBar();
$bar->add('Fichier', $menu);
echo "bar=ok\n";
PHP;

$menubarValuesC = $compilePhp('ui-menubar-values', $menubarValues, 'c');
$menubarValuesSource = (string) file_get_contents($menubarValuesC['cPath']);
T::ok(str_contains($menubarValuesSource, 'cy_ui_menu_bar'), 'menubar : façade MenuBar');
T::ok(str_contains($menubarValuesSource, 'cy_ui_menu_activate'), 'menubar : activation programmatique');

$menubarGtk = trim((string) shell_exec('pkg-config --exists gtk4 2>/dev/null && echo yes || true')) === 'yes';
$menubarDisplay = getenv('DISPLAY') !== false && getenv('DISPLAY') !== '';

if (!$hasGcc || !$menubarGtk) {
    T::skip('GTK4 dev absent : exécution des menus natifs non testée');
} elseif (!$menubarDisplay) {
    T::skip('DISPLAY absent : exécution des menus natifs non testée');
} else {
    $menubarElf = (new PhpCompiler($menubarRoot))->compile(
        $menubarRoot . '/src/app.php',
        null,
        'elf',
    );
    $menubarRunner = 'timeout 8 ' . escapeshellarg((string) $menubarElf['binaryPath']);

    if (getenv('DBUS_SESSION_BUS_ADDRESS') === false
        && trim((string) shell_exec('command -v dbus-run-session 2>/dev/null')) !== '') {
        $menubarRunner = 'dbus-run-session -- ' . $menubarRunner;
    }

    $menubarOut = [];
    $menubarStatus = 0;
    exec($menubarRunner . ' 2>&1', $menubarOut, $menubarStatus);
    T::ok(
        $menubarStatus === 0,
        'menubar : page avec barre de menus exécutée',
        implode("\n", $menubarOut),
    );

    $valuesElf = $compilePhp('ui-menubar-values', $menubarValues, 'elf');
    $valuesRunner = 'timeout 8 ' . escapeshellarg((string) $valuesElf['binaryPath']);

    if (getenv('DBUS_SESSION_BUS_ADDRESS') === false
        && trim((string) shell_exec('command -v dbus-run-session 2>/dev/null')) !== '') {
        $valuesRunner = 'dbus-run-session -- ' . $valuesRunner;
    }

    $valuesOut = [];
    $valuesStatus = 0;
    exec($valuesRunner . ' 2>&1', $valuesOut, $valuesStatus);
    $valuesText = implode("\n", $valuesOut);
    T::ok(str_contains($valuesText, 'activate=ok'), 'menubar : action native', $valuesText);
    T::ok(str_contains($valuesText, 'bar=ok'), 'menubar : barre construite', $valuesText);
    T::ok($valuesStatus === 0, 'menubar : valeurs exécutées', $valuesText);
}

$exampleMenubarC = (new PhpCompiler(
    $menubarRoot,
    [],
    [],
    [dirname(__DIR__) . '/examples/native-menu-bar.ui'],
))->compile(
    dirname(__DIR__) . '/examples/native-menubar.php',
    null,
    'c',
);
$exampleMenubarSource = (string) file_get_contents($exampleMenubarC['cPath']);
T::ok(
    str_contains($exampleMenubarSource, 'cy_ui_menu_bar')
        && str_contains($exampleMenubarSource, 'cy_ui_menu_add_item'),
    'menubar : exemple du dépôt',
);

$removeTree($menubarRoot);

// ---------------------------------------------------------------------------
T::section('50. UI — raccourcis clavier GTK');

$shortcutRoot = sys_get_temp_dir() . '/cyonima_shortcuts_' . getmypid();
$removeTree($shortcutRoot);
mkdir($shortcutRoot . '/src', 0775, true);
mkdir($shortcutRoot . '/templates', 0775, true);

$shortcutTemplate = <<<'UI'
@params(string $title, callable():void $onOpen, callable():void $onQuit)

<window title="{{ $title }}" width="420" height="320">
    <shortcut key="<Control>o" on-activate="{{ $onOpen }}" />
    <shortcut key="<Control>q" on-activate="{{ $onQuit }}" />
    <box vertical="true" spacing="8">
        <label>Ctrl+O / Ctrl+Q</label>
    </box>
</window>
UI;

$shortcutApp = <<<'PHP'
<?php
namespace App;

use Application;
use Cyonima\Templates\ShortcutPage;

$onOpen = function (): void {
    echo "open\n";
};
$onQuit = function (): void {
    echo "quit\n";
};

$application = new Application('com.cyonima.shortcut.page');
$window = ShortcutPage::build('Raccourcis', $onOpen, $onQuit);
$window->present();
$application->after(60, function () use ($application): void {
    $application->quit();
});
$application->run();
PHP;

$shortcutGenerated = (new TemplateCompiler('ShortcutPage'))->compile($shortcutTemplate);
T::ok(
    str_contains($shortcutGenerated, "->shortcut('<Control>o', (\$onOpen))"),
    'raccourcis : Ctrl+O généré',
);
T::ok(
    str_contains($shortcutGenerated, "->shortcut('<Control>q', (\$onQuit))"),
    'raccourcis : Ctrl+Q généré',
);

file_put_contents($shortcutRoot . '/templates/shortcut-page.ui', $shortcutTemplate);
file_put_contents($shortcutRoot . '/src/app.php', $shortcutApp);

$shortcutC = (new PhpCompiler($shortcutRoot))->compile($shortcutRoot . '/src/app.php', null, 'c');
$shortcutCSource = (string) file_get_contents($shortcutC['cPath']);
T::ok(str_contains($shortcutCSource, 'cy_ui_shortcut'), 'raccourcis : primitive native');
T::ok(
    str_contains($shortcutCSource, '<Control>o')
        && str_contains($shortcutCSource, '<Control>q'),
    'raccourcis : deux raccourcis générés',
);

$shortcutErrors = [
    [
        'clé manquante',
        '<window><shortcut on-activate="{{ $f }}" /></window>',
        'attribut "key" requis pour <shortcut>',
    ],
    [
        'callback manquant',
        '<window><shortcut key="<Control>q" /></window>',
        'attribut "on-activate" requis pour <shortcut>',
    ],
    [
        'raccourci dans un fragment',
        '<box><shortcut key="<Control>q" on-activate="{{ $f }}" /></box>',
        '<shortcut> exige une fenêtre',
    ],
];

foreach ($shortcutErrors as $index => [$label, $source, $needle]) {
    try {
        (new TemplateCompiler('ShortcutBroken' . $index))->compile($source);
        T::ok(false, 'raccourcis : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'raccourcis : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

$shortcutGtk = trim((string) shell_exec('pkg-config --exists gtk4 2>/dev/null && echo yes || true')) === 'yes';
$shortcutDisplay = getenv('DISPLAY') !== false && getenv('DISPLAY') !== '';

if (!$hasGcc || !$shortcutGtk) {
    T::skip('GTK4 dev absent : exécution des raccourcis non testée');
} elseif (!$shortcutDisplay) {
    T::skip('DISPLAY absent : exécution des raccourcis non testée');
} else {
    $shortcutElf = (new PhpCompiler($shortcutRoot))->compile(
        $shortcutRoot . '/src/app.php',
        null,
        'elf',
    );
    $shortcutRunner = 'timeout 8 ' . escapeshellarg((string) $shortcutElf['binaryPath']);

    if (getenv('DBUS_SESSION_BUS_ADDRESS') === false
        && trim((string) shell_exec('command -v dbus-run-session 2>/dev/null')) !== '') {
        $shortcutRunner = 'dbus-run-session -- ' . $shortcutRunner;
    }

    $shortcutOut = [];
    $shortcutStatus = 0;
    exec($shortcutRunner . ' 2>&1', $shortcutOut, $shortcutStatus);
    T::ok(
        $shortcutStatus === 0,
        'raccourcis : page avec shortcuts exécutée',
        implode("\n", $shortcutOut),
    );
}

$exampleShortcutC = (new PhpCompiler(
    $shortcutRoot,
    [],
    [],
    [dirname(__DIR__) . '/examples/native-shortcuts.ui'],
))->compile(
    dirname(__DIR__) . '/examples/native-shortcuts.php',
    null,
    'c',
);
$exampleShortcutSource = (string) file_get_contents($exampleShortcutC['cPath']);
T::ok(
    str_contains($exampleShortcutSource, 'cy_ui_shortcut'),
    'raccourcis : exemple du dépôt',
);

$removeTree($shortcutRoot);

// ---------------------------------------------------------------------------
T::section('51. UI — items de menu cochables, icônes et accélérateurs');

$menuItemRoot = sys_get_temp_dir() . '/cyonima_menu_items_' . getmypid();
$removeTree($menuItemRoot);
mkdir($menuItemRoot . '/src', 0775, true);
mkdir($menuItemRoot . '/templates', 0775, true);

$menuItemTemplate = <<<'UI'
@params(string $title, callable():void $onOpen, callable(bool):void $onToggle)

<window title="{{ $title }}" width="440" height="320">
    <menu-bar>
        <menu label="Affichage">
            <item label="Ouvrir" accel="<Control>o" icon="document-open" on-click="{{ $onOpen }}" />
            <separator />
            <item label="Mode compact" checkable="true" checked="true" on-toggle="{{ $onToggle }}" />
        </menu>
    </menu-bar>
    <box vertical="true" spacing="8">
        <label>Menu enrichi</label>
    </box>
</window>
UI;

$menuItemApp = <<<'PHP'
<?php
namespace App;

use Application;
use Cyonima\Templates\MenuItemPage;

$onOpen = function (): void {
    echo "open\n";
};
$onToggle = function (bool $checked): void {
    if ($checked) {
        echo "checked\n";
    } else {
        echo "unchecked\n";
    }
};

$application = new Application('com.cyonima.menu.items.page');
$window = MenuItemPage::build('Items', $onOpen, $onToggle);
$window->present();
$application->after(60, function () use ($application): void {
    $application->quit();
});
$application->run();
PHP;

$menuItemGenerated = (new TemplateCompiler('MenuItemPage'))->compile($menuItemTemplate);
T::ok(
    str_contains(
        $menuItemGenerated,
        "addStyledItem('Ouvrir', '<Control>o', 'document-open'",
    ),
    'items : accélérateur et icône',
);
T::ok(
    str_contains(
        $menuItemGenerated,
        "addCheckItem('Mode compact', true, '', '', (\$onToggle))",
    ),
    'items : élément cochable',
);

file_put_contents($menuItemRoot . '/templates/menu-item-page.ui', $menuItemTemplate);
file_put_contents($menuItemRoot . '/src/app.php', $menuItemApp);

$menuItemC = (new PhpCompiler($menuItemRoot))->compile(
    $menuItemRoot . '/src/app.php',
    null,
    'c',
);
$menuItemCSource = (string) file_get_contents($menuItemC['cPath']);
T::ok(str_contains($menuItemCSource, 'cy_ui_menu_add_item_ex'), 'items : item stylé natif');
T::ok(
    str_contains($menuItemCSource, 'cy_ui_menu_add_check_item'),
    'items : item cochable natif',
);

$menuItemErrors = [
    [
        'checkable sans on-toggle',
        '<window><menu-bar><menu label="F"><item label="x" checkable="true" /></menu></menu-bar></window>',
        'on-toggle requis pour un <item checkable>',
    ],
    [
        'checkable avec on-click',
        '<window><menu-bar><menu label="F"><item label="x" checkable="true" on-click="{{ $f }}" on-toggle="{{ $g }}" /></menu></menu-bar></window>',
        'on-click est interdit pour un <item checkable>',
    ],
    [
        'item sans on-click',
        '<window><menu-bar><menu label="F"><item label="x" /></menu></menu-bar></window>',
        'on-click requis pour <item>',
    ],
];

foreach ($menuItemErrors as $index => [$label, $source, $needle]) {
    try {
        (new TemplateCompiler('MenuItemBroken' . $index))->compile($source);
        T::ok(false, 'items : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'items : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

$menuItemValues = <<<'PHP'
<?php
$app = new Application('com.cyonima.menu.items.values');
$menu = new Menu();
$state = false;
$menu->addCheckItem('Option', false, '', '', function (bool $checked) use (&$state): void {
    $state = $checked;
});
$menu->setChecked(0, true);

if ($state) {
    echo "check=ok\n";
}

$menu->addStyledItem('Ouvrir', '<Control>o', 'document-open', function (): void {
    echo "open\n";
});
echo "styled=ok\n";
PHP;

$menuItemValuesC = $compilePhp('ui-menu-items-values', $menuItemValues, 'c');
$menuItemValuesSource = (string) file_get_contents($menuItemValuesC['cPath']);
T::ok(str_contains($menuItemValuesSource, 'cy_ui_menu_add_check_item'), 'items : façade cochable');
T::ok(str_contains($menuItemValuesSource, 'cy_ui_menu_set_check'), 'items : état programmatique');

$menuItemGtk = trim((string) shell_exec('pkg-config --exists gtk4 2>/dev/null && echo yes || true')) === 'yes';
$menuItemDisplay = getenv('DISPLAY') !== false && getenv('DISPLAY') !== '';

if (!$hasGcc || !$menuItemGtk) {
    T::skip('GTK4 dev absent : exécution des items de menu non testée');
} elseif (!$menuItemDisplay) {
    T::skip('DISPLAY absent : exécution des items de menu non testée');
} else {
    $menuItemElf = (new PhpCompiler($menuItemRoot))->compile(
        $menuItemRoot . '/src/app.php',
        null,
        'elf',
    );
    $menuItemRunner = 'timeout 8 ' . escapeshellarg((string) $menuItemElf['binaryPath']);

    if (getenv('DBUS_SESSION_BUS_ADDRESS') === false
        && trim((string) shell_exec('command -v dbus-run-session 2>/dev/null')) !== '') {
        $menuItemRunner = 'dbus-run-session -- ' . $menuItemRunner;
    }

    $menuItemOut = [];
    $menuItemStatus = 0;
    exec($menuItemRunner . ' 2>&1', $menuItemOut, $menuItemStatus);
    T::ok(
        $menuItemStatus === 0,
        'items : page avec menu enrichi exécutée',
        implode("\n", $menuItemOut),
    );

    $valuesElf = $compilePhp('ui-menu-items-values', $menuItemValues, 'elf');
    $valuesRunner = 'timeout 8 ' . escapeshellarg((string) $valuesElf['binaryPath']);

    if (getenv('DBUS_SESSION_BUS_ADDRESS') === false
        && trim((string) shell_exec('command -v dbus-run-session 2>/dev/null')) !== '') {
        $valuesRunner = 'dbus-run-session -- ' . $valuesRunner;
    }

    $valuesOut = [];
    $valuesStatus = 0;
    exec($valuesRunner . ' 2>&1', $valuesOut, $valuesStatus);
    $valuesText = implode("\n", $valuesOut);
    T::ok(str_contains($valuesText, 'check=ok'), 'items : état coché', $valuesText);
    T::ok(str_contains($valuesText, 'styled=ok'), 'items : icône et accélérateur', $valuesText);
    T::ok($valuesStatus === 0, 'items : valeurs exécutées', $valuesText);
}

$removeTree($menuItemRoot);

// ---------------------------------------------------------------------------
T::section('52. UI — items radio et états dynamiques des menus');

$radioRoot = sys_get_temp_dir() . '/cyonima_radio_' . getmypid();
$removeTree($radioRoot);
mkdir($radioRoot . '/src', 0775, true);
mkdir($radioRoot . '/templates', 0775, true);

$radioTemplate = <<<'UI'
@params(string $title, bool $enabled, callable(string):void $onAlign, callable():void $onOpen)

<window title="{{ $title }}" width="440" height="320">
    <menu-bar>
        <menu label="Affichage">
            <item label="Gauche" radio="align" value="left" checked="true" on-select="{{ $onAlign }}" />
            <item label="Centre" radio="align" value="center" on-select="{{ $onAlign }}" />
            <item label="Droite" radio="align" value="right" on-select="{{ $onAlign }}" />
            <separator />
            <item label="Ouvrir" enabled="{{ $enabled }}" on-click="{{ $onOpen }}" />
        </menu>
    </menu-bar>
    <box vertical="true" spacing="8">
        <label>Radio</label>
    </box>
</window>
UI;

$radioApp = <<<'PHP'
<?php
namespace App;

use Application;
use Cyonima\Templates\RadioPage;

$onAlign = function (string $value): void {
    echo 'align=', $value, "\n";
};
$onOpen = function (): void {
    echo "open\n";
};

$application = new Application('com.cyonima.radio.page');
$window = RadioPage::build('Radio', true, $onAlign, $onOpen);
$window->present();
$application->after(60, function () use ($application): void {
    $application->quit();
});
$application->run();
PHP;

$radioGenerated = (new TemplateCompiler('RadioPage'))->compile($radioTemplate);
T::ok(
    str_contains(
        $radioGenerated,
        "addRadioItem('align', 'Gauche', 'left', true, '', '', (\$onAlign))",
    ),
    'radio : item sélectionné généré',
);
T::ok(
    str_contains(
        $radioGenerated,
        "addRadioItem('align', 'Centre', 'center', false, '', '', (\$onAlign))",
    ),
    'radio : item non sélectionné généré',
);
T::ok(
    str_contains($radioGenerated, 'setEnabled(3, ($enabled))'),
    'radio : état dynamique généré',
);

file_put_contents($radioRoot . '/templates/radio-page.ui', $radioTemplate);
file_put_contents($radioRoot . '/src/app.php', $radioApp);

$radioC = (new PhpCompiler($radioRoot))->compile($radioRoot . '/src/app.php', null, 'c');
$radioCSource = (string) file_get_contents($radioC['cPath']);
T::ok(str_contains($radioCSource, 'cy_ui_menu_add_radio_item'), 'radio : item radio natif');
T::ok(str_contains($radioCSource, 'cy_ui_menu_set_enabled'), 'radio : activation native');

$radioErrors = [
    [
        'radio sans value',
        '<window><menu-bar><menu label="F"><item label="x" radio="g" on-select="{{ $f }}" /></menu></menu-bar></window>',
        'value requis pour un <item radio>',
    ],
    [
        'radio sans on-select',
        '<window><menu-bar><menu label="F"><item label="x" radio="g" value="v" /></menu></menu-bar></window>',
        'on-select requis pour un <item radio>',
    ],
    [
        'radio avec on-click',
        '<window><menu-bar><menu label="F"><item label="x" radio="g" value="v" on-click="{{ $f }}" on-select="{{ $g }}" /></menu></menu-bar></window>',
        'on-click et on-toggle sont interdits pour un <item radio>',
    ],
];

foreach ($radioErrors as $index => [$label, $source, $needle]) {
    try {
        (new TemplateCompiler('RadioBroken' . $index))->compile($source);
        T::ok(false, 'radio : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'radio : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

$radioValues = <<<'PHP'
<?php
$app = new Application('com.cyonima.radio.values');
$menu = new Menu();
$selected = '';
$menu->addRadioItem('align', 'Gauche', 'left', true, '', '', function (string $value) use (&$selected): void {
    $selected = $value;
});
$menu->addRadioItem('align', 'Centre', 'center', false, '', '', function (string $value) use (&$selected): void {
    $selected = $value;
});
$menu->setRadio(1);

if ($selected === 'center') {
    echo "radio=ok\n";
}

$menu->addStyledItem('Ouvrir', '', '', function (): void {
    echo "open\n";
});
$menu->setEnabled(2, false);

if (!$menu->isEnabled(2)) {
    echo "disabled=ok\n";
}

$menu->setEnabled(2, true);

if ($menu->isEnabled(2)) {
    echo "enabled=ok\n";
}
PHP;

$radioValuesC = $compilePhp('ui-radio-values', $radioValues, 'c');
$radioValuesSource = (string) file_get_contents($radioValuesC['cPath']);
T::ok(str_contains($radioValuesSource, 'cy_ui_menu_select_radio'), 'radio : sélection programmatique');
T::ok(str_contains($radioValuesSource, 'cy_ui_menu_is_enabled'), 'radio : lecture d’état');

$radioGtk = trim((string) shell_exec('pkg-config --exists gtk4 2>/dev/null && echo yes || true')) === 'yes';
$radioDisplay = getenv('DISPLAY') !== false && getenv('DISPLAY') !== '';

if (!$hasGcc || !$radioGtk) {
    T::skip('GTK4 dev absent : exécution des items radio non testée');
} elseif (!$radioDisplay) {
    T::skip('DISPLAY absent : exécution des items radio non testée');
} else {
    $radioElf = (new PhpCompiler($radioRoot))->compile(
        $radioRoot . '/src/app.php',
        null,
        'elf',
    );
    $radioRunner = 'timeout 8 ' . escapeshellarg((string) $radioElf['binaryPath']);

    if (getenv('DBUS_SESSION_BUS_ADDRESS') === false
        && trim((string) shell_exec('command -v dbus-run-session 2>/dev/null')) !== '') {
        $radioRunner = 'dbus-run-session -- ' . $radioRunner;
    }

    $radioOut = [];
    $radioStatus = 0;
    exec($radioRunner . ' 2>&1', $radioOut, $radioStatus);
    T::ok(
        $radioStatus === 0,
        'radio : page avec items radio exécutée',
        implode("\n", $radioOut),
    );

    $valuesElf = $compilePhp('ui-radio-values', $radioValues, 'elf');
    $valuesRunner = 'timeout 8 ' . escapeshellarg((string) $valuesElf['binaryPath']);

    if (getenv('DBUS_SESSION_BUS_ADDRESS') === false
        && trim((string) shell_exec('command -v dbus-run-session 2>/dev/null')) !== '') {
        $valuesRunner = 'dbus-run-session -- ' . $valuesRunner;
    }

    $valuesOut = [];
    $valuesStatus = 0;
    exec($valuesRunner . ' 2>&1', $valuesOut, $valuesStatus);
    $valuesText = implode("\n", $valuesOut);
    T::ok(str_contains($valuesText, 'radio=ok'), 'radio : sélection d’un item', $valuesText);
    T::ok(str_contains($valuesText, 'disabled=ok'), 'radio : désactivation dynamique', $valuesText);
    T::ok(str_contains($valuesText, 'enabled=ok'), 'radio : réactivation dynamique', $valuesText);
    T::ok($valuesStatus === 0, 'radio : valeurs exécutées', $valuesText);
}

$exampleRadioC = (new PhpCompiler(
    $radioRoot,
    [],
    [],
    [dirname(__DIR__) . '/examples/native-menu-bar.ui'],
))->compile(
    dirname(__DIR__) . '/examples/native-menubar.php',
    null,
    'c',
);
$exampleRadioSource = (string) file_get_contents($exampleRadioC['cPath']);
T::ok(
    str_contains($exampleRadioSource, 'cy_ui_menu_add_radio_item')
        && str_contains($exampleRadioSource, 'cy_ui_menu_set_enabled'),
    'radio : exemple du dépôt',
);

$removeTree($radioRoot);

// ---------------------------------------------------------------------------
T::section('53. UI — binding bidirectionnel');

$bindingRoot = sys_get_temp_dir() . '/cyonima_binding_' . getmypid();
$removeTree($bindingRoot);
mkdir($bindingRoot . '/src', 0775, true);

$bindingSource = <<<'PHP'
<?php
$app = new Application('com.cyonima.binding.test');
$name = 'Alice';
$entry = new Entry('Nom');
$entryBinding = Binding::entry($entry, function () use (&$name): string {
    return $name;
}, function (string $value) use (&$name): void {
    $name = $value;
});

if ($entry->text() === 'Alice') {
    echo "entry-init=ok\n";
}

$entry->setText('Bob');

if ($name === 'Bob') {
    echo "entry-write=ok\n";
}

$name = 'Carol';
$entryBinding->refresh();

if ($entry->text() === 'Carol') {
    echo "entry-refresh=ok\n";
}

$enabled = false;
$check = new CheckButton('Activer', false);
$checkBinding = Binding::check($check, function () use (&$enabled): bool {
    return $enabled;
}, function (bool $active) use (&$enabled): void {
    $enabled = $active;
});
$check->setActive(true);

if ($enabled) {
    echo "check-write=ok\n";
}

$enabled = false;
$checkBinding->refresh();

if (!$check->active()) {
    echo "check-refresh=ok\n";
}

$model = new StringList(['a', 'b', 'c']);
$drop = new DropDown($model, 0);
$index = 0;
$dropBinding = Binding::dropIndex($drop, function () use (&$index): int {
    return $index;
}, function (int $value) use (&$index): void {
    $index = $value;
});
$drop->setSelected(2);

if ($index === 2) {
    echo "drop-write=ok\n";
}

$index = 1;
$dropBinding->refresh();

if ($drop->selectedIndex() === 1) {
    echo "drop-refresh=ok\n";
}

$text = 'a';
$dropTextBinding = Binding::dropText($drop, function () use (&$text): string {
    return $text;
}, function (string $value) use (&$text): void {
    $text = $value;
});
$drop->setSelected(2);

if ($text === 'c') {
    echo "text-write=ok\n";
}

$text = 'b';
$dropTextBinding->refresh();

if ($drop->selectedText() === 'b') {
    echo "text-refresh=ok\n";
}

$label = new Label('');
$labelText = 'Départ';
$labelBinding = Binding::label($label, function () use (&$labelText): string {
    return $labelText;
});
$labelText = 'Arrivée';
$labelBinding->refresh();

if ($label->text() === 'Arrivée') {
    echo "label=ok\n";
}

$list = new ListBox();
$list->append(new Label('Un'));
$list->append(new Label('Deux'));
$selected = 0;
$listBinding = Binding::listIndex($list, function () use (&$selected): int {
    return $selected;
}, function (int $value) use (&$selected): void {
    $selected = $value;
});
$list->activate(1);

if ($selected === 1) {
    echo "list-write=ok\n";
}

$selected = 0;
$listBinding->refresh();

if ($list->selectedIndex() === 0) {
    echo "list-refresh=ok\n";
}
PHP;

file_put_contents($bindingRoot . '/src/app.php', $bindingSource);

$bindingC = (new PhpCompiler($bindingRoot))->compile(
    $bindingRoot . '/src/app.php',
    null,
    'c',
);
$bindingCSource = (string) file_get_contents($bindingC['cPath']);
T::ok(str_contains($bindingCSource, 'Binding__entry'), 'binding : Entry');
T::ok(str_contains($bindingCSource, 'Binding__check'), 'binding : CheckButton');
T::ok(str_contains($bindingCSource, 'Binding__dropIndex'), 'binding : DropDown index');
T::ok(str_contains($bindingCSource, 'Binding__dropText'), 'binding : DropDown texte');
T::ok(str_contains($bindingCSource, 'Binding__listIndex'), 'binding : ListBox');
T::ok(str_contains($bindingCSource, 'cy_ui_on_entry_changed_closure'), 'binding : signal changed');
T::ok(str_contains($bindingCSource, 'cy_ui_label_text'), 'binding : lecture Label');

$bindingError = <<<'PHP'
<?php
$app = new Application('com.cyonima.binding.error');
$entry = new Entry('Nom');
$name = 'x';
$bad = Binding::entry($entry, function () use (&$name): void {
    echo $name;
}, function (string $value) use (&$name): void {
    $name = $value;
});
PHP;

file_put_contents($bindingRoot . '/src/error.php', $bindingError);

try {
    (new PhpCompiler($bindingRoot))->compile(
        $bindingRoot . '/src/error.php',
        null,
        'c',
    );
    T::ok(false, 'binding : getter incompatible');
} catch (Cyonima\Compiler\CompilerException $exception) {
    T::ok(
        str_contains($exception->getMessage(), 'callable():string')
            || str_contains($exception->getMessage(), 'type'),
        'binding : getter incompatible',
        $exception->getMessage(),
    );
}

$bindingGtk = trim((string) shell_exec('pkg-config --exists gtk4 2>/dev/null && echo yes || true')) === 'yes';
$bindingDisplay = getenv('DISPLAY') !== false && getenv('DISPLAY') !== '';

if (!$hasGcc || !$bindingGtk) {
    T::skip('GTK4 dev absent : exécution du binding non testée');
} elseif (!$bindingDisplay) {
    T::skip('DISPLAY absent : exécution du binding non testée');
} else {
    $bindingElf = (new PhpCompiler($bindingRoot))->compile(
        $bindingRoot . '/src/app.php',
        null,
        'elf',
    );
    $bindingRunner = 'timeout 8 ' . escapeshellarg((string) $bindingElf['binaryPath']);

    if (getenv('DBUS_SESSION_BUS_ADDRESS') === false
        && trim((string) shell_exec('command -v dbus-run-session 2>/dev/null')) !== '') {
        $bindingRunner = 'dbus-run-session -- ' . $bindingRunner;
    }

    $bindingOut = [];
    $bindingStatus = 0;
    exec($bindingRunner . ' 2>&1', $bindingOut, $bindingStatus);
    $bindingText = implode("\n", $bindingOut);
    $bindingExpected = [
        'entry-init=ok',
        'entry-write=ok',
        'entry-refresh=ok',
        'check-write=ok',
        'check-refresh=ok',
        'drop-write=ok',
        'drop-refresh=ok',
        'text-write=ok',
        'text-refresh=ok',
        'label=ok',
        'list-write=ok',
        'list-refresh=ok',
    ];
    $bindingMissing = [];

    foreach ($bindingExpected as $line) {
        if (!str_contains($bindingText, $line)) {
            $bindingMissing[] = $line;
        }
    }

    T::ok(
        $bindingMissing === [],
        'binding : widget → état et état → widget',
        implode(', ', $bindingMissing) . "\n" . $bindingText,
    );
    T::ok($bindingStatus === 0, 'binding : exécution terminée', $bindingText);
}

$exampleBindingC = (new PhpCompiler($bindingRoot))->compile(
    dirname(__DIR__) . '/examples/native-binding.php',
    null,
    'c',
);
$exampleBindingSource = (string) file_get_contents($exampleBindingC['cPath']);
T::ok(
    str_contains($exampleBindingSource, 'Binding__entry')
        && str_contains($exampleBindingSource, 'Binding__label'),
    'binding : exemple du dépôt',
);

$removeTree($bindingRoot);

// ---------------------------------------------------------------------------
T::section('54. UI — GtkListView et ListView');

$viewRoot = sys_get_temp_dir() . '/cyonima_listview_' . getmypid();
$removeTree($viewRoot);
mkdir($viewRoot . '/src', 0775, true);
mkdir($viewRoot . '/templates', 0775, true);

$viewTemplate = <<<'UI'
@params(string $title, array<string> $options, int $selected, callable(int):void $onActivate)

<window title="{{ $title }}" width="440" height="420">
    <box vertical="true" spacing="8">
        <list-view options="{{ $options }}" selected="{{ $selected }}" on-activate="{{ $onActivate }}" />
        <list-view options="Un,Deux,Trois" selected="2" single-click="false" />
    </box>
</window>
UI;

$viewApp = <<<'PHP'
<?php
namespace App;

use Application;
use Cyonima\Templates\ListViewPage;

$options = ['A', 'B', 'C'];
$onActivate = function (int $index): void {
    echo 'activate=', $index, "\n";
};

$application = new Application('com.cyonima.listview.page');
$window = ListViewPage::build('ListView', $options, 1, $onActivate);
$window->present();
$application->after(60, function () use ($application): void {
    $application->quit();
});
$application->run();
PHP;

$viewGenerated = (new TemplateCompiler('ListViewPage'))->compile($viewTemplate);
T::ok(str_contains($viewGenerated, 'new ListView('), 'list-view : widget généré');
T::ok(str_contains($viewGenerated, 'new StringList('), 'list-view : modèle généré');
T::ok(str_contains($viewGenerated, "->setSingleClick(false)"), 'list-view : clic simple');
T::ok(str_contains($viewGenerated, '->onActivated(($onActivate))'), 'list-view : callback');

file_put_contents($viewRoot . '/templates/list-view-page.ui', $viewTemplate);
file_put_contents($viewRoot . '/src/app.php', $viewApp);

$viewC = (new PhpCompiler($viewRoot))->compile($viewRoot . '/src/app.php', null, 'c');
$viewCSource = (string) file_get_contents($viewC['cPath']);
T::ok(str_contains($viewCSource, 'cy_ui_list_view'), 'list-view : widget natif');
T::ok(str_contains($viewCSource, 'cy_ui_list_view_selected'), 'list-view : sélection native');
T::ok(str_contains($viewCSource, 'cy_ui_list_view_set_single_click'), 'list-view : clic native');
T::ok(str_contains($viewCSource, 'cy_ui_on_list_view_activated_closure'), 'list-view : signal activate');

$viewErrors = [
    [
        'sans source',
        '<window><list-view /></window>',
        '<list-view> exige soit "options", soit "model", soit "items"',
    ],
    [
        'double source',
        '<window><list-view options="a,b" model="{{ $m }}" /></window>',
        '<list-view> exige soit "options", soit "model", soit "items"',
    ],
    [
        'items statique',
        '<window><list-view items="x" /></window>',
        '"items" doit être {{ expression }}',
    ],
];

foreach ($viewErrors as $index => [$label, $source, $needle]) {
    try {
        (new TemplateCompiler('ViewBroken' . $index))->compile($source);
        T::ok(false, 'list-view : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'list-view : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

$viewValues = <<<'PHP'
<?php
$app = new Application('com.cyonima.listview.values');
$model = new StringList(['a', 'b', 'c']);
$view = new ListView($model, 1);

if ($view->selectedIndex() === 1 && $view->selectedText() === 'b') {
    echo "initial=ok\n";
}

$view->selectText('c');

if ($view->selectedIndex() === 2) {
    echo "select-text=ok\n";
}

$model->append('d');

if ($model->count() === 4) {
    echo "model-append=ok\n";
}

$activated = -1;
$view->onActivated(function (int $index) use (&$activated): void {
    $activated = $index;
});
$view->activate(2);

if ($activated === 2) {
    echo "activate=ok\n";
}

$boundIndex = 0;
$indexBinding = Binding::viewIndex($view, function () use (&$boundIndex): int {
    return $boundIndex;
}, function (int $value) use (&$boundIndex): void {
    $boundIndex = $value;
});
$view->activate(1);

if ($boundIndex === 1) {
    echo "view-index=ok\n";
}

$boundIndex = 3;
$indexBinding->refresh();

if ($view->selectedIndex() === 3) {
    echo "view-refresh=ok\n";
}

$boundText = 'a';
$textBinding = Binding::viewText($view, function () use (&$boundText): string {
    return $boundText;
}, function (string $value) use (&$boundText): void {
    $boundText = $value;
});
$view->activate(2);

if ($boundText === 'c') {
    echo "view-text=ok\n";
}

$boundText = 'b';
$textBinding->refresh();

if ($view->selectedText() === 'b') {
    echo "view-text-refresh=ok\n";
}
PHP;

$viewValuesC = $compilePhp('ui-listview-values', $viewValues, 'c');
$viewValuesSource = (string) file_get_contents($viewValuesC['cPath']);
T::ok(str_contains($viewValuesSource, 'cy_ui_list_view'), 'list-view : façade');
T::ok(str_contains($viewValuesSource, 'Binding__viewIndex'), 'list-view : binding index');
T::ok(str_contains($viewValuesSource, 'Binding__viewText'), 'list-view : binding texte');

$viewGtk = trim((string) shell_exec('pkg-config --exists gtk4 2>/dev/null && echo yes || true')) === 'yes';
$viewDisplay = getenv('DISPLAY') !== false && getenv('DISPLAY') !== '';

if (!$hasGcc || !$viewGtk) {
    T::skip('GTK4 dev absent : exécution de ListView non testée');
} elseif (!$viewDisplay) {
    T::skip('DISPLAY absent : exécution de ListView non testée');
} else {
    $viewElf = (new PhpCompiler($viewRoot))->compile(
        $viewRoot . '/src/app.php',
        null,
        'elf',
    );
    $viewRunner = 'timeout 8 ' . escapeshellarg((string) $viewElf['binaryPath']);

    if (getenv('DBUS_SESSION_BUS_ADDRESS') === false
        && trim((string) shell_exec('command -v dbus-run-session 2>/dev/null')) !== '') {
        $viewRunner = 'dbus-run-session -- ' . $viewRunner;
    }

    $viewOut = [];
    $viewStatus = 0;
    exec($viewRunner . ' 2>&1', $viewOut, $viewStatus);
    T::ok(
        $viewStatus === 0,
        'list-view : page avec GtkListView exécutée',
        implode("\n", $viewOut),
    );

    $valuesElf = $compilePhp('ui-listview-values', $viewValues, 'elf');
    $valuesRunner = 'timeout 8 ' . escapeshellarg((string) $valuesElf['binaryPath']);

    if (getenv('DBUS_SESSION_BUS_ADDRESS') === false
        && trim((string) shell_exec('command -v dbus-run-session 2>/dev/null')) !== '') {
        $valuesRunner = 'dbus-run-session -- ' . $valuesRunner;
    }

    $valuesOut = [];
    $valuesStatus = 0;
    exec($valuesRunner . ' 2>&1', $valuesOut, $valuesStatus);
    $valuesText = implode("\n", $valuesOut);
    T::ok(str_contains($valuesText, 'initial=ok'), 'list-view : sélection initiale', $valuesText);
    T::ok(str_contains($valuesText, 'select-text=ok'), 'list-view : sélection par texte', $valuesText);
    T::ok(str_contains($valuesText, 'model-append=ok'), 'list-view : modèle dynamique', $valuesText);
    T::ok(str_contains($valuesText, 'activate=ok'), 'list-view : activation', $valuesText);
    T::ok(str_contains($valuesText, 'view-index=ok'), 'list-view : binding index', $valuesText);
    T::ok(str_contains($valuesText, 'view-refresh=ok'), 'list-view : refresh index', $valuesText);
    T::ok(str_contains($valuesText, 'view-text=ok'), 'list-view : binding texte', $valuesText);
    T::ok(str_contains($valuesText, 'view-text-refresh=ok'), 'list-view : refresh texte', $valuesText);
    T::ok($valuesStatus === 0, 'list-view : valeurs exécutées', $valuesText);
}

$exampleViewC = (new PhpCompiler(
    $viewRoot,
    [],
    [],
    [dirname(__DIR__) . '/examples/native-listview.ui'],
))->compile(
    dirname(__DIR__) . '/examples/native-listview.php',
    null,
    'c',
);
$exampleViewSource = (string) file_get_contents($exampleViewC['cPath']);
T::ok(
    str_contains($exampleViewSource, 'cy_ui_list_view'),
    'list-view : exemple du dépôt',
);

$removeTree($viewRoot);

// ---------------------------------------------------------------------------
T::section('55. Templates — diagnostics fichier et ligne');

try {
    (new TemplateCompiler('Diag'))->compile(
        "<window>\n    <frame />\n</window>",
    );
    T::ok(false, 'diagnostic : erreur de syntaxe localisée');
} catch (Cyonima\Compiler\CompilerException $exception) {
    T::ok(
        str_contains($exception->getMessage(), 'template ligne 2'),
        'diagnostic : erreur de syntaxe localisée',
        $exception->getMessage(),
    );
}

$diagRoot = sys_get_temp_dir() . '/cyonima_diag_' . getmypid();
$removeTree($diagRoot);
mkdir($diagRoot . '/templates', 0775, true);

file_put_contents(
    $diagRoot . '/templates/broken.ui',
    "@params(string \$title)\n\n<window title=\"{{ \$title }}\">\n    <frame />\n</window>\n",
);
file_put_contents(
    $diagRoot . '/templates/semantic.ui',
    "@params(string \$title)\n\n<window title=\"{{ \$title }}\">\n    <label>{{ missing_function() }}</label>\n</window>\n",
);
file_put_contents(
    $diagRoot . '/app-broken.php',
    "<?php\nuse Cyonima\\Templates\\Broken;\n\$w = Broken::build('x');\n",
);
file_put_contents(
    $diagRoot . '/app-semantic.php',
    "<?php\nuse Cyonima\\Templates\\Semantic;\n\$w = Semantic::build('x');\n",
);

try {
    (new PhpCompiler($diagRoot))->compile($diagRoot . '/app-broken.php', null, 'c');
    T::ok(false, 'diagnostic : fichier et ligne de syntaxe');
} catch (Cyonima\Compiler\CompilerException $exception) {
    $message = $exception->getMessage();
    T::ok(
        str_contains($message, 'templates/broken.ui')
            && str_contains($message, 'template ligne 4'),
        'diagnostic : fichier et ligne de syntaxe',
        $message,
    );
}

unlink($diagRoot . '/templates/broken.ui');

try {
    (new PhpCompiler($diagRoot))->compile($diagRoot . '/app-semantic.php', null, 'c');
    T::ok(false, 'diagnostic : fichier et ligne sémantique');
} catch (Cyonima\Compiler\CompilerException $exception) {
    $message = $exception->getMessage();
    T::ok(
        str_contains($message, 'templates/semantic.ui')
            && str_contains($message, 'template ligne 4')
            && str_contains($message, 'fonction inconnue'),
        'diagnostic : fichier et ligne sémantique',
        $message,
    );
}

$removeTree($diagRoot);

// ---------------------------------------------------------------------------
T::section('56. Callables typés avec objets');

$objectCallableSource = <<<'PHP'
<?php
class Todo
{
    public string $title;

    public function __construct(string $title)
    {
        $this->title = $title;
    }
}

class Formatter
{
    public callable(Todo):string $format;

    public function __construct(callable(Todo):string $format)
    {
        $this->format = $format;
    }

    public function render(Todo $todo): string
    {
        $callback = $this->format;

        return $callback($todo);
    }
}

$format = function (Todo $todo): string {
    return '[' . $todo->title . ']';
};

$make = function (string $title): Todo {
    return new Todo($title);
};

$nullable = function (?Todo $todo): string {
    if ($todo === null) {
        return 'aucun';
    }

    return $todo->title;
};

$first = new Todo('Compilateur');
echo $format($first), "\n";
$second = $make('Callables');
echo $format($second), "\n";
echo $nullable(null), ' ', $nullable($second), "\n";

$formatter = new Formatter(function (Todo $todo): string {
    return '<' . $todo->title . '>';
});
echo $formatter->render($second), "\n";
PHP;

file_put_contents($phpRoot . '/src/object-callables.php', $objectCallableSource);

$objectCallableC = (new PhpCompiler($phpRoot))->compile(
    $phpRoot . '/src/object-callables.php',
    null,
    'c',
);
$objectCallableCSource = (string) file_get_contents($objectCallableC['cPath']);
T::ok(str_contains($objectCallableCSource, 'CyObj_Todo'), 'callables objets : classe C');
T::ok(str_contains($objectCallableCSource, 'cy_closure_'), 'callables objets : closures C');
T::ok(
    str_contains($objectCallableCSource, 'fn_Formatter__render'),
    'callables objets : propriété callable',
);

if (!$hasGcc) {
    T::skip('gcc absent : exécution des callables objets non testée');
} else {
    $objectCallableElf = (new PhpCompiler($phpRoot))->compile(
        $phpRoot . '/src/object-callables.php',
        null,
        'elf',
    );
    $objectCallableExpected = <<<'TXT'
[Compilateur]
[Callables]
aucun Callables
<Callables>

TXT;
    T::same(
        $objectCallableExpected,
        shell_exec(escapeshellarg((string) $objectCallableElf['binaryPath']) . ' 2>&1'),
        'callables objets : paramètre, retour et nullabilité',
    );
}

$objectCallableErrors = [
    [
        'paramètre tableau',
        '<?php $f = function (array $items): void {};',
        'paramètre callable non supporté',
    ],
    [
        'retour tableau',
        '<?php $f = function (): array { return []; };',
        'retour callable non supporté',
    ],
    [
        'classe inconnue',
        '<?php $f = function (Missing $item): void {};',
        'paramètre callable non supporté',
    ],
];

foreach ($objectCallableErrors as $index => [$label, $source, $needle]) {
    try {
        $compilePhp('object-callable-error-' . $index, $source, 'c');
        T::ok(false, 'callables objets : erreur ' . $label);
    } catch (Cyonima\Compiler\CompilerException $exception) {
        T::ok(
            str_contains($exception->getMessage(), $needle),
            'callables objets : erreur ' . $label,
            $exception->getMessage(),
        );
    }
}

$objectCallableTypeError = <<<'PHP'
<?php
class Todo
{
    public string $title;

    public function __construct(string $title)
    {
        $this->title = $title;
    }
}

$format = function (Todo $todo): string {
    return $todo->title;
};

echo $format('texte');
PHP;

try {
    $compilePhp('object-callable-type-error', $objectCallableTypeError, 'c');
    T::ok(false, 'callables objets : argument incompatible');
} catch (Cyonima\Compiler\CompilerException $exception) {
    T::ok(
        str_contains($exception->getMessage(), 'appel de closure'),
        'callables objets : argument incompatible',
        $exception->getMessage(),
    );
}

$exampleObjectCallableC = (new PhpCompiler($phpRoot))->compile(
    dirname(__DIR__) . '/examples/native-object-callables.php',
    null,
    'c',
);
$exampleObjectCallableSource = (string) file_get_contents($exampleObjectCallableC['cPath']);
T::ok(
    str_contains($exampleObjectCallableSource, 'CyObj_Todo'),
    'callables objets : exemple du dépôt',
);

// ---------------------------------------------------------------------------
T::section('57. Modèles — notifications et bindings réactifs');

$reactiveSource = <<<'PHP'
<?php
$app = new Application('com.cyonima.reactive.test');
$model = new StringList(['a', 'b']);
$position = -1;
$model->onIndexChanged(function (int $changed) use (&$position): void {
    $position = $changed;
});
$model->append('c');

if ($position === 2) {
    echo "model-changed=ok\n";
}

$drop = new DropDown($model, 0);
$text = 'a';
$dropBinding = Binding::dropText($drop, function () use (&$text): string {
    return $text;
}, function (string $value) use (&$text): void {
    $text = $value;
});
$text = 'c';
$model->append('d');

if ($drop->selectedText() === 'c') {
    echo "reactive-drop=ok\n";
}

$view = new ListView($model, 0);
$viewText = 'a';
$viewBinding = Binding::viewText($view, function () use (&$viewText): string {
    return $viewText;
}, function (string $value) use (&$viewText): void {
    $viewText = $value;
});
$viewText = 'd';
$model->append('e');

if ($view->selectedText() === 'd') {
    echo "reactive-view=ok\n";
}
PHP;

$reactiveC = $compilePhp('reactive-model', $reactiveSource, 'c');
$reactiveCSource = (string) file_get_contents($reactiveC['cPath']);
T::ok(
    str_contains($reactiveCSource, 'cy_ui_string_list_on_changed'),
    'réactif : signal items-changed',
);
T::ok(
    str_contains($reactiveCSource, 'cy_ui_list_view'),
    'réactif : vue liée au modèle',
);

$reactiveError = <<<'PHP'
<?php
$app = new Application('com.cyonima.reactive.error');
$model = new StringList(['a']);
$model->onIndexChanged(function (string $value): void {
    echo $value;
});
PHP;

file_put_contents($phpRoot . '/src/reactive-error.php', $reactiveError);

try {
    (new PhpCompiler($phpRoot))->compile(
        $phpRoot . '/src/reactive-error.php',
        null,
        'c',
    );
    T::ok(false, 'réactif : callback de modèle incompatible');
} catch (Cyonima\Compiler\CompilerException $exception) {
    T::ok(
        str_contains($exception->getMessage(), 'callable(int):void'),
        'réactif : callback de modèle incompatible',
        $exception->getMessage(),
    );
}

if (!$hasGcc) {
    T::skip('gcc absent : exécution réactive non testée');
} else {
    $reactiveGtk = trim((string) shell_exec('pkg-config --exists gtk4 2>/dev/null && echo yes || true')) === 'yes';
    $reactiveDisplay = getenv('DISPLAY') !== false && getenv('DISPLAY') !== '';

    if (!$reactiveGtk) {
        T::skip('GTK4 dev absent : exécution réactive non testée');
    } elseif (!$reactiveDisplay) {
        T::skip('DISPLAY absent : exécution réactive non testée');
    } else {
        $reactiveElf = $compilePhp('reactive-model', $reactiveSource, 'elf');
        $reactiveRunner = 'timeout 8 ' . escapeshellarg((string) $reactiveElf['binaryPath']);

        if (getenv('DBUS_SESSION_BUS_ADDRESS') === false
            && trim((string) shell_exec('command -v dbus-run-session 2>/dev/null')) !== '') {
            $reactiveRunner = 'dbus-run-session -- ' . $reactiveRunner;
        }

        $reactiveOut = [];
        $reactiveStatus = 0;
        exec($reactiveRunner . ' 2>&1', $reactiveOut, $reactiveStatus);
        $reactiveText = implode("\n", $reactiveOut);
        T::ok(
            str_contains($reactiveText, 'model-changed=ok'),
            'réactif : notification items-changed',
            $reactiveText,
        );
        T::ok(
            str_contains($reactiveText, 'reactive-drop=ok'),
            'réactif : DropDown après ajout au modèle',
            $reactiveText,
        );
        T::ok(
            str_contains($reactiveText, 'reactive-view=ok'),
            'réactif : ListView après ajout au modèle',
            $reactiveText,
        );
        T::ok($reactiveStatus === 0, 'réactif : exécution terminée', $reactiveText);
    }
}

// ---------------------------------------------------------------------------
T::section('58. ORM — relations belongsTo et hasMany');

$relationsC = (new PhpCompiler($phpRoot))->compile(
    dirname(__DIR__) . '/examples/native-relations.php',
    null,
    'c',
);
$relationsCSource = (string) file_get_contents($relationsC['cPath']);
T::ok(
    str_contains($relationsCSource, 'CyObj_Cyonima__Database__BelongsTo'),
    'relations : classe BelongsTo',
);
T::ok(
    str_contains($relationsCSource, 'CyObj_Cyonima__Database__HasMany'),
    'relations : classe HasMany',
);
T::ok(
    str_contains($relationsCSource, 'cy_map_set_string_string'),
    'relations : hydratation par row map',
);

$relationGuardSource = <<<'PHP'
<?php
use Cyonima\Database\Connection;
use Cyonima\Database\Table;
use RuntimeException;

$db = new Connection(':memory:');
$db->exec('CREATE TABLE todos (id INTEGER PRIMARY KEY, user_id INTEGER)');
$table = new Table($db, 'todos');

$missing = $table->belongsTo('todos', 999);
echo 'missing=', count($missing->row()), "\n";

try {
    $table->hasMany('todos', 'user_id; DROP', 1);
    echo "unexpected\n";
} catch (RuntimeException $error) {
    echo 'guard=', $error->getMessage(), "\n";
}
PHP;

$relationGuardC = $compilePhp('relations-guard', $relationGuardSource, 'c');
$relationGuardCSource = (string) file_get_contents($relationGuardC['cPath']);
T::ok(
    str_contains($relationGuardCSource, 'fn_Cyonima__Database__BelongsTo'),
    'relations : fabrique belongsTo',
);
T::ok(
    str_contains($relationGuardCSource, 'fn_Cyonima__Database__HasMany'),
    'relations : fabrique hasMany',
);

if (!$hasGcc || !$hasSqlite) {
    T::skip('gcc ou SQLite absent : exécution des relations non testée');
} else {
    $relationsElf = (new PhpCompiler($phpRoot))->compile(
        dirname(__DIR__) . '/examples/native-relations.php',
        null,
        'elf',
    );
    $relationsExpected = <<<'TXT'
propriétaire : Alice
tâches : 2
- Première tâche
- Deuxième tâche

TXT;
    T::same(
        $relationsExpected,
        shell_exec(escapeshellarg((string) $relationsElf['binaryPath']) . ' 2>&1'),
        'relations : belongsTo, hasMany et hydratation typée',
    );

    $guardElf = $compilePhp('relations-guard', $relationGuardSource, 'elf');
    $guardExpected = <<<'TXT'
missing=0
guard=identifiant SQL invalide : user_id; DROP

TXT;
    T::same(
        $guardExpected,
        shell_exec(escapeshellarg((string) $guardElf['binaryPath']) . ' 2>&1'),
        'relations : absence et garde d’identifiant',
    );
}

// ---------------------------------------------------------------------------
T::section('59. Application de démonstration complète');

$demoTemplates = dirname(__DIR__) . '/examples/native-demo/templates';
$demoSource = dirname(__DIR__) . '/examples/native-demo/app.php';
$demoCompiler = new PhpCompiler($phpRoot, [], [], [$demoTemplates]);
$demoC = $demoCompiler->compile($demoSource, null, 'c');
$demoCSource = (string) file_get_contents($demoC['cPath']);
T::ok(
    str_contains($demoCSource, 'CyObj_Cyonima__Templates__DemoPage'),
    'démo : template principal compilé',
);
T::ok(
    str_contains($demoCSource, 'CyObj_Cyonima__Database__Connection'),
    'démo : base SQLite intégrée',
);
T::ok(
    str_contains($demoCSource, 'CyObj_ListView'),
    'démo : ListView intégrée',
);
T::ok(
    str_contains($demoCSource, 'CyObj_App__Task'),
    'démo : entité métier',
);
T::ok(
    str_contains($demoCSource, 'cy_ui_shortcut'),
    'démo : raccourci clavier',
);

$widgetTemplate = <<<'UI'
@params(Widget $widget)

<window title="Widget" width="200" height="100">
    <box vertical="true">
        <widget value="{{ $widget }}" />
    </box>
</window>
UI;

$widgetGenerated = (new TemplateCompiler('WidgetPage'))->compile($widgetTemplate);
T::ok(
    str_contains($widgetGenerated, '->append(($widget));'),
    'démo : widget préconstruit composé',
);

try {
    (new TemplateCompiler('WidgetBroken'))->compile(
        '<window><widget /></window>',
    );
    T::ok(false, 'démo : erreur widget sans value');
} catch (Cyonima\Compiler\CompilerException $exception) {
    T::ok(
        str_contains($exception->getMessage(), 'attribut "value" requis pour <widget>'),
        'démo : erreur widget sans value',
        $exception->getMessage(),
    );
}

$demoGtk = trim((string) shell_exec('pkg-config --exists gtk4 2>/dev/null && echo yes || true')) === 'yes';
$demoDisplay = getenv('DISPLAY') !== false && getenv('DISPLAY') !== '';

if (!$hasGcc || !$hasSqlite || !$demoGtk) {
    T::skip('GTK4/SQLite dev absent : exécution de la démo non testée');
} elseif (!$demoDisplay) {
    T::skip('DISPLAY absent : exécution de la démo non testée');
} else {
    $demoElf = $demoCompiler->compile($demoSource, null, 'elf');
    $demoRunner = 'timeout 8 ' . escapeshellarg((string) $demoElf['binaryPath']) . ' --auto';

    if (getenv('DBUS_SESSION_BUS_ADDRESS') === false
        && trim((string) shell_exec('command -v dbus-run-session 2>/dev/null')) !== '') {
        $demoRunner = 'dbus-run-session -- ' . $demoRunner;
    }

    $demoOut = [];
    $demoStatus = 0;
    exec($demoRunner . ' 2>&1', $demoOut, $demoStatus);
    T::ok(
        $demoStatus === 0,
        'démo : application complète exécutée',
        implode("\n", $demoOut),
    );
}

// ---------------------------------------------------------------------------
T::section('60. SQLite — requêtes préparées');

$preparedC = (new PhpCompiler($phpRoot))->compile(
    dirname(__DIR__) . '/examples/native-prepared.php',
    null,
    'c',
);
$preparedCSource = (string) file_get_contents($preparedC['cPath']);
T::ok(str_contains($preparedCSource, 'fn_sqlite_prepare'), 'préparé : préparation native');
T::ok(str_contains($preparedCSource, 'fn_sqlite_bind_string'), 'préparé : bind string');
T::ok(str_contains($preparedCSource, 'fn_sqlite_bind_int'), 'préparé : bind int');
T::ok(str_contains($preparedCSource, 'fn_sqlite_bind_float'), 'préparé : bind float');
T::ok(str_contains($preparedCSource, 'fn_sqlite_bind_null'), 'préparé : bind null');
T::ok(str_contains($preparedCSource, 'fn_sqlite_statement_row_string'), 'préparé : row map');

$preparedErrorSource = <<<'PHP'
<?php
use Cyonima\Database\Connection;
use RuntimeException;

$db = new Connection(':memory:');

try {
    $statement = $db->prepare('SELECT FROM');
    echo "unexpected\n";
} catch (RuntimeException $error) {
    echo "prepare-error\n";
}
PHP;

$preparedErrorC = $compilePhp('prepared-error', $preparedErrorSource, 'c');
T::ok(
    str_contains((string) file_get_contents($preparedErrorC['cPath']), 'sqlite_prepare'),
    'préparé : erreur de préparation',
);

if (!$hasGcc || !$hasSqlite) {
    T::skip('gcc ou SQLite absent : exécution des requêtes préparées non testée');
} else {
    $preparedElf = (new PhpCompiler($phpRoot))->compile(
        dirname(__DIR__) . '/examples/native-prepared.php',
        null,
        'elf',
    );
    $preparedExpected = <<<'TXT'
O'Reilly 12.5
O'Reilly note=
comptes=2

TXT;
    T::same(
        $preparedExpected,
        shell_exec(escapeshellarg((string) $preparedElf['binaryPath']) . ' 2>&1'),
        'préparé : binds, step, colonnes et row map',
    );

    $preparedErrorElf = $compilePhp('prepared-error', $preparedErrorSource, 'elf');
    T::same(
        "prepare-error\n",
        shell_exec(escapeshellarg((string) $preparedErrorElf['binaryPath']) . ' 2>&1'),
        'préparé : préparation invalide rejetée',
    );
}

// ---------------------------------------------------------------------------
T::section('61. ORM — eager loading simple');

$eagerC = (new PhpCompiler($phpRoot))->compile(
    dirname(__DIR__) . '/examples/native-eager.php',
    null,
    'c',
);
$eagerCSource = (string) file_get_contents($eagerC['cPath']);
T::ok(
    str_contains($eagerCSource, 'fn_Cyonima__Database__Table__findMany'),
    'eager : findMany',
);
T::ok(
    str_contains($eagerCSource, 'fn_Cyonima__Database__Table__whereIn'),
    'eager : whereIn',
);
T::ok(
    str_contains($eagerCSource, 'fn_Cyonima__Database__HasMany__statement'),
    'eager : statement de relation',
);
T::ok(str_contains($eagerCSource, 'fn_sqlite_prepare'), 'eager : requête préparée');

$eagerGuardSource = <<<'PHP'
<?php
use Cyonima\Database\Connection;
use Cyonima\Database\Table;
use RuntimeException;

$db = new Connection(':memory:');
$db->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, user_id INTEGER)');
$table = new Table($db, 'tasks');

try {
    $statement = $table->whereIn('user_id; DROP', [1]);
    echo "unexpected\n";
} catch (RuntimeException $error) {
    echo 'guard=', $error->getMessage(), "\n";
}
PHP;

$eagerGuardC = $compilePhp('eager-guard', $eagerGuardSource, 'c');
T::ok(
    str_contains((string) file_get_contents($eagerGuardC['cPath']), 'whereIn'),
    'eager : garde d’identifiant',
);

if (!$hasGcc || !$hasSqlite) {
    T::skip('gcc ou SQLite absent : exécution de l’eager loading non testée');
} else {
    $eagerElf = (new PhpCompiler($phpRoot))->compile(
        dirname(__DIR__) . '/examples/native-eager.php',
        null,
        'elf',
    );
    $eagerExpected = <<<'TXT'
utilisateurs=2 Alice Bob
tâches=3
tâches Alice=2
- Première
- Deuxième
vide=0

TXT;
    T::same(
        $eagerExpected,
        shell_exec(escapeshellarg((string) $eagerElf['binaryPath']) . ' 2>&1'),
        'eager : findMany, whereIn et relation par statement',
    );

    $eagerGuardElf = $compilePhp('eager-guard', $eagerGuardSource, 'elf');
    T::same(
        "guard=identifiant SQL invalide : user_id; DROP\n",
        shell_exec(escapeshellarg((string) $eagerGuardElf['binaryPath']) . ' 2>&1'),
        'eager : garde d’identifiant à l’exécution',
    );
}

// ---------------------------------------------------------------------------
T::section('62. ORM — query builder typé');

$queryC = (new PhpCompiler($phpRoot))->compile(
    dirname(__DIR__) . '/examples/native-query-builder.php',
    null,
    'c',
);
$queryCSource = (string) file_get_contents($queryC['cPath']);
T::ok(
    str_contains($queryCSource, 'CyObj_Cyonima__Database__QueryBuilder'),
    'query builder : classe QueryBuilder',
);
T::ok(
    str_contains($queryCSource, 'CyObj_Cyonima__Database__QueryCondition'),
    'query builder : conditions',
);
T::ok(str_contains($queryCSource, 'fn_sqlite_prepare'), 'query builder : SQL préparé');

$queryGuardSource = <<<'PHP'
<?php
use Cyonima\Database\Connection;
use Cyonima\Database\Table;
use RuntimeException;

$db = new Connection(':memory:');
$db->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT, note TEXT)');
$items = new Table($db, 'items');
$items->insert(['name' => 'Alice', 'note' => '']);
$items->insert(['name' => 'Bob', 'note' => 'yes']);

$null = $items->query()->whereNull('note');
echo 'null=', $null->count(), "\n";
$notNull = $items->query()->whereNotNull('note');
echo 'notnull=', $notNull->count(), "\n";

try {
    $items->query()->whereString('name', 'DROP', 'x');
    echo "unexpected\n";
} catch (RuntimeException $error) {
    echo 'operator=', $error->getMessage(), "\n";
}

try {
    $items->query()->whereString('name; DROP', '=', 'x');
    echo "unexpected\n";
} catch (RuntimeException $error) {
    echo 'column=', $error->getMessage(), "\n";
}

try {
    $items->query()->limit(-1);
    echo "unexpected\n";
} catch (RuntimeException $error) {
    echo 'limit=', $error->getMessage(), "\n";
}
PHP;

file_put_contents($phpRoot . '/src/query-guard.php', $queryGuardSource);

$queryGuardC = (new PhpCompiler($phpRoot))->compile(
    $phpRoot . '/src/query-guard.php',
    null,
    'c',
);
$queryGuardCSource = (string) file_get_contents($queryGuardC['cPath']);
T::ok(
    str_contains($queryGuardCSource, 'QueryBuilder__whereNull'),
    'query builder : whereNull',
);

if (!$hasGcc || !$hasSqlite) {
    T::skip('gcc ou SQLite absent : exécution du query builder non testée');
} else {
    $queryElf = (new PhpCompiler($phpRoot))->compile(
        dirname(__DIR__) . '/examples/native-query-builder.php',
        null,
        'elf',
    );
    $queryExpected = <<<'TXT'
Carol 30
count=1
exists=yes
active=2 Alice Carol
float=Bob
offset=Bob

TXT;
    T::same(
        $queryExpected,
        shell_exec(escapeshellarg((string) $queryElf['binaryPath']) . ' 2>&1'),
        'query builder : where, order, limit, offset, count',
    );

    $guardElf = (new PhpCompiler($phpRoot))->compile(
        $phpRoot . '/src/query-guard.php',
        null,
        'elf',
    );
    $guardExpected = <<<'TXT'
null=0
notnull=2
operator=opérateur SQL invalide : DROP
column=identifiant SQL invalide : name; DROP
limit=limite négative

TXT;
    T::same(
        $guardExpected,
        shell_exec(escapeshellarg((string) $guardElf['binaryPath']) . ' 2>&1'),
        'query builder : null et gardes',
    );
}

// ---------------------------------------------------------------------------
T::section('63. ORM — jointures typées');

$joinC = (new PhpCompiler($phpRoot))->compile(
    dirname(__DIR__) . '/examples/native-joins.php',
    null,
    'c',
);
$joinCSource = (string) file_get_contents($joinC['cPath']);
T::ok(
    str_contains($joinCSource, 'CyObj_Cyonima__Database__QueryJoin'),
    'jointures : classe QueryJoin',
);
T::ok(
    str_contains($joinCSource, 'fn_Cyonima__Database__QueryBuilder__join'),
    'jointures : méthode join',
);
T::ok(
    str_contains($joinCSource, 'fn_Cyonima__Database__QueryBuilder__leftJoin'),
    'jointures : méthode leftJoin',
);
T::ok(
    str_contains($joinCSource, 'fn_Cyonima__Database__QueryBuilder__selectAs'),
    'jointures : alias de colonne',
);

$joinGuardSource = <<<'PHP'
<?php
use Cyonima\Database\Connection;
use Cyonima\Database\Table;
use RuntimeException;

$db = new Connection(':memory:');
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
$db->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, user_id INTEGER)');
$users = new Table($db, 'users');

try {
    $users->query()->join('tasks', 'tasks.user_id', 'users.id; DROP');
    echo "unexpected\n";
} catch (RuntimeException $error) {
    echo 'join=', $error->getMessage(), "\n";
}
PHP;

file_put_contents($phpRoot . '/src/join-guard.php', $joinGuardSource);

if (!$hasGcc || !$hasSqlite) {
    T::skip('gcc ou SQLite absent : exécution des jointures non testée');
} else {
    $joinElf = (new PhpCompiler($phpRoot))->compile(
        dirname(__DIR__) . '/examples/native-joins.php',
        null,
        'elf',
    );
    $joinExpected = <<<'TXT'
Alice / Compilateur
Alice / Templates
Bob / ORM
inner=3
Alice / Compilateur
Alice / Templates
Bob / ORM
Carol / —
left=4
filtered=1
guard=identifiant SQL invalide : tasks; DROP

TXT;
    T::same(
        $joinExpected,
        shell_exec(escapeshellarg((string) $joinElf['binaryPath']) . ' 2>&1'),
        'jointures : inner, left, where et alias',
    );

    $joinGuardElf = (new PhpCompiler($phpRoot))->compile(
        $phpRoot . '/src/join-guard.php',
        null,
        'elf',
    );
    T::same(
        "join=identifiant SQL invalide : users.id; DROP\n",
        shell_exec(escapeshellarg((string) $joinGuardElf['binaryPath']) . ' 2>&1'),
        'jointures : garde de colonne',
    );
}

// ---------------------------------------------------------------------------
T::section('64. IDE — recherche projet');

$searchRoot = sys_get_temp_dir() . '/cyonima_search_' . getmypid();
$removeTree($searchRoot);
mkdir($searchRoot . '/src', 0775, true);
mkdir($searchRoot . '/vendor', 0775, true);

file_put_contents(
    $searchRoot . '/src/a.php',
    "<?php\n\$value = 'Needle';\necho \$value;\n",
);
file_put_contents(
    $searchRoot . '/src/b.md',
    "# Notes\nUne needle minuscule.\n",
);
file_put_contents(
    $searchRoot . '/vendor/ignored.php',
    "<?php\n\$ignored = 'needle';\n",
);

$searchFiles = ProjectSearch::files($searchRoot);
T::same(2, count($searchFiles), 'recherche : vendor exclu');
$searchResults = ProjectSearch::search($searchRoot, 'needle');
T::same(2, count($searchResults), 'recherche : insensible à la casse');
T::same('src/a.php', $searchResults[0]['path'], 'recherche : chemin relatif');
T::same(2, $searchResults[0]['line'], 'recherche : numéro de ligne');
T::ok($searchResults[0]['column'] > 0, 'recherche : colonne');
T::same(
    [],
    ProjectSearch::search($searchRoot, ''),
    'recherche : requête vide',
);
T::same(
    1,
    count(ProjectSearch::search($searchRoot, 'needle', 1)),
    'recherche : limite',
);

$searchOpened = null;
$searchPanel = new SearchPanel(
    $searchRoot,
    function (string $path, int $line) use (&$searchOpened): void {
        $searchOpened = [$path, $line];
    },
);
T::same(2, $searchPanel->search('needle'), 'recherche UI : résultats');
$searchPanel->activateRow(0);
T::same(
    $searchRoot . '/src/a.php',
    $searchOpened[0] ?? null,
    'recherche UI : ouverture du fichier',
);
T::same(2, $searchOpened[1] ?? null, 'recherche UI : ligne ouverte');

$removeTree($searchRoot);

// ---------------------------------------------------------------------------
T::section('65. IDE — remplacement projet');

$replaceRoot = sys_get_temp_dir() . '/cyonima_replace_' . getmypid();
$removeTree($replaceRoot);
mkdir($replaceRoot . '/src', 0775, true);
mkdir($replaceRoot . '/vendor', 0775, true);

file_put_contents(
    $replaceRoot . '/src/a.php',
    "<?php\n\$value = 'Needle';\necho 'needle';\n",
);
file_put_contents(
    $replaceRoot . '/src/b.md',
    "# Notes\nUne needle minuscule.\nNeedle encore.\n",
);
file_put_contents(
    $replaceRoot . '/src/c.php',
    "needles needle\n",
);
file_put_contents(
    $replaceRoot . '/vendor/ignored.php',
    "needle\n",
);

T::same(
    2,
    count(ProjectSearch::search($replaceRoot, 'Needle', 200, ['caseSensitive' => true])),
    'remplacement : recherche sensible à la casse',
);
T::same(
    0,
    count(ProjectSearch::search($replaceRoot, 'needl', 200, ['wholeWord' => true])),
    'remplacement : mot entier exclut les préfixes',
);
T::same(
    5,
    count(ProjectSearch::search($replaceRoot, 'n[a-z]+dle', 200, ['regex' => true])),
    'remplacement : recherche par expression régulière',
);

try {
    ProjectSearch::search($replaceRoot, '(', 200, ['regex' => true]);
    T::ok(false, 'remplacement : expression invalide signalée');
} catch (\InvalidArgumentException) {
    T::ok(true, 'remplacement : expression invalide signalée');
}

$replaceReport = ProjectSearch::replace($replaceRoot, 'needle', 'thread');
T::same(3, $replaceReport['changes'], 'remplacement : fichiers modifiés');
T::same(6, $replaceReport['replacements'], 'remplacement : occurrences remplacées');
T::same(
    "threads thread\n",
    file_get_contents($replaceRoot . '/src/c.php'),
    'remplacement : contenu réécrit',
);
T::same(
    "needle\n",
    file_get_contents($replaceRoot . '/vendor/ignored.php'),
    'remplacement : vendor préservé',
);
T::same(
    ['files' => [], 'changes' => 0, 'replacements' => 0],
    ProjectSearch::replace($replaceRoot, ''),
    'remplacement : requête vide',
);

file_put_contents($replaceRoot . '/src/d.php', "abc-123 abc-456\n");
$regexReport = ProjectSearch::replace(
    $replaceRoot,
    'abc-(\d+)',
    'id:$1',
    ['regex' => true],
);
T::same(2, $regexReport['replacements'], 'remplacement : expression régulière');
T::same(
    "id:123 id:456\n",
    file_get_contents($replaceRoot . '/src/d.php'),
    'remplacement : références arrière',
);

file_put_contents($replaceRoot . '/src/g.php', "needle\n");
ProjectSearch::replace($replaceRoot, 'needle', '$1');
T::same(
    "\$1\n",
    file_get_contents($replaceRoot . '/src/g.php'),
    'remplacement : dollar littéral hors regex',
);

file_put_contents($replaceRoot . '/src/e.php', "needles needle\n");
$wordReport = ProjectSearch::replace(
    $replaceRoot,
    'needle',
    'thread',
    ['wholeWord' => true],
);
T::same(1, $wordReport['replacements'], 'remplacement : mot entier');
T::same(
    "needles thread\n",
    file_get_contents($replaceRoot . '/src/e.php'),
    'remplacement : mot entier préservé',
);

file_put_contents($replaceRoot . '/src/f.php', "Needle needle\n");
$caseReport = ProjectSearch::replace(
    $replaceRoot,
    'Needle',
    'Thread',
    ['caseSensitive' => true],
);
T::same(1, $caseReport['replacements'], 'remplacement : casse respectée');
T::same(
    "Thread needle\n",
    file_get_contents($replaceRoot . '/src/f.php'),
    'remplacement : casse préservée',
);

$uiRoot = sys_get_temp_dir() . '/cyonima_replace_ui_' . getmypid();
$removeTree($uiRoot);
mkdir($uiRoot . '/src', 0775, true);
file_put_contents($uiRoot . '/src/one.php', "alpha beta alpha\n");
file_put_contents($uiRoot . '/src/two.php', "Alpha\n");

$replaceUiReport = null;
$replacePanel = new SearchPanel(
    $uiRoot,
    function (string $path, int $line): void {
    },
    function (array $report) use (&$replaceUiReport): void {
        $replaceUiReport = $report;
    },
);

T::same(2, $replacePanel->search('alpha'), 'remplacement UI : résultats');
$replacePanel->setOption('caseSensitive', true);
T::same(1, $replacePanel->search('alpha'), 'remplacement UI : option casse');
$replacePanel->setOption('caseSensitive', false);
$replacePanel->setOption('wholeWord', true);
T::same(0, $replacePanel->search('alp'), 'remplacement UI : option mot entier');
$replacePanel->setOption('wholeWord', false);
$replacePanel->setOption('regex', true);
T::same(0, $replacePanel->search('('), 'remplacement UI : expression invalide');
$replacePanel->setOption('regex', false);
T::same(
    ['caseSensitive' => false, 'wholeWord' => false, 'regex' => false],
    $replacePanel->options(),
    'remplacement UI : options rétablies',
);

$replacePanel->setQuery('alpha')->setReplacement('gamma');
$uiReport = $replacePanel->replaceAll();
T::same(2, $uiReport['changes'], 'remplacement UI : fichiers modifiés');
T::same(3, $uiReport['replacements'], 'remplacement UI : occurrences');
T::same($uiReport, $replaceUiReport, 'remplacement UI : notification');
T::same(0, $replacePanel->resultCount(), 'remplacement UI : résultats rafraîchis');
T::same(
    "gamma beta gamma\n",
    file_get_contents($uiRoot . '/src/one.php'),
    'remplacement UI : contenu réécrit',
);

$removeTree($uiRoot);
$removeTree($replaceRoot);

// ---------------------------------------------------------------------------
T::section('66. IDE — curseur par ligne et colonne');

$tabEditor = new CodeEditor('php', "ligne 1\nligne 2\nligne 3");
$tabEditor->moveCursorTo(1, 3);
$cursor = $tabEditor->cursor();
T::same(1, $cursor['line'], 'curseur : ligne');
T::same(3, $cursor['character'], 'curseur : colonne');

$tabEditor->moveCursorToLine(2);
$cursor = $tabEditor->cursor();
T::same(2, $cursor['line'], 'curseur : ligne seule');
T::same(0, $cursor['character'], 'curseur : colonne remise à zéro');

$tabEditor->moveCursorTo(999, 0);
T::same(2, $tabEditor->cursor()['line'], 'curseur : ligne bornée');

$tabEditor->moveCursorTo(0, 6);
T::same(6, $tabEditor->cursor()['character'], 'curseur : colonne sur première ligne');

// ---------------------------------------------------------------------------
T::section('67. IDE — navigation rapide (Ctrl+P)');

$quickPaths = [
    'src/Controller/UserController.php',
    'src/Service/UserService.php',
    'docs/readme.md',
    'README.md',
];

T::same(
    'README.md',
    QuickOpenMatcher::match($quickPaths, 'readme')[0] ?? null,
    'navigation : nom de fichier prioritaire',
);
T::same(
    'src/Controller/UserController.php',
    QuickOpenMatcher::match($quickPaths, 'userctrl')[0] ?? null,
    'navigation : filtrage flou',
);
T::same(
    'src/Service/UserService.php',
    QuickOpenMatcher::match($quickPaths, 'usersvc')[0] ?? null,
    'navigation : seconde correspondance floue',
);
T::same(
    [],
    QuickOpenMatcher::match($quickPaths, 'zzz'),
    'navigation : aucune correspondance',
);
T::same(
    2,
    count(QuickOpenMatcher::match($quickPaths, '', 2)),
    'navigation : limite de résultats',
);

$quickRoot = sys_get_temp_dir() . '/cyonima_quick_' . getmypid();
$removeTree($quickRoot);
mkdir($quickRoot . '/src', 0775, true);
file_put_contents($quickRoot . '/src/Controller.php', "<?php\n");
file_put_contents($quickRoot . '/src/Service.php', "<?php\n");
file_put_contents($quickRoot . '/readme.md', "# Notes\n");

$quickWindow = new UI\Window('Navigation rapide');
$quickWindow->present();

$quickOpened = null;
$quickOpen = new QuickOpen(
    $quickWindow,
    $quickRoot,
    function (string $path) use (&$quickOpened): void {
        $quickOpened = $path;
    },
);

T::same(3, $quickOpen->filter(), 'navigation UI : fichiers du projet');
T::same(1, $quickOpen->setQuery('ctrl')->count(), 'navigation UI : filtre');
T::same(1, $quickOpen->choose(0), 'navigation UI : sélection');
T::same(
    $quickRoot . '/src/Controller.php',
    $quickOpened,
    'navigation UI : fichier ouvert',
);
T::ok(!$quickOpen->visible(), 'navigation UI : popover fermé après sélection');

$quickWindow->raw()->close();
$removeTree($quickRoot);

// ---------------------------------------------------------------------------
T::section('68. IDE — palette de commandes');

$commandLabels = [
    'Fichier : Enregistrer',
    'Affichage : Recherche',
    'Exécuter : compiler en natif',
    'Exécuter : fichier courant',
];

T::same(
    'Affichage : Recherche',
    QuickOpenMatcher::matchLabels($commandLabels, 'recherche')[0] ?? null,
    'commandes : correspondance exacte',
);
T::same(
    'Fichier : Enregistrer',
    QuickOpenMatcher::matchLabels($commandLabels, 'enreg')[0] ?? null,
    'commandes : filtrage flou',
);
T::same(
    'Exécuter : compiler en natif',
    QuickOpenMatcher::matchLabels($commandLabels, 'compile')[0] ?? null,
    'commandes : mot du libellé',
);
T::same(
    2,
    count(QuickOpenMatcher::matchLabels($commandLabels, '', 2)),
    'commandes : limite de résultats',
);

$paletteWindow = new UI\Window('Palette de commandes');
$paletteWindow->present();

$executed = [];
$palette = new CommandPalette($paletteWindow, [
    ['label' => 'Fichier : Enregistrer', 'detail' => 'Ctrl+S', 'action' => function () use (&$executed): void {
        $executed[] = 'save';
    }],
    ['label' => 'Affichage : Recherche', 'action' => function () use (&$executed): void {
        $executed[] = 'search';
    }],
    ['label' => 'Exécuter : fichier courant', 'action' => function () use (&$executed): void {
        $executed[] = 'run';
    }],
]);

T::same(3, $palette->filter(), 'commandes UI : liste complète');
$palette->setQuery('recherche');
T::same(1, $palette->count(), 'commandes UI : filtre');
T::same(['Affichage : Recherche'], $palette->labels(), 'commandes UI : libellé retenu');
T::same(1, $palette->choose(0), 'commandes UI : exécution');
T::same(['search'], $executed, 'commandes UI : action appelée');
T::ok(!$palette->visible(), 'commandes UI : palette fermée après exécution');

$paletteWindow->raw()->close();

// ---------------------------------------------------------------------------
T::section('69. IDE — problèmes LSP');

T::same(
    '/home/ludo/projet/src/a.php',
    Diagnostics::localPath('file:///home/ludo/projet/src/a.php'),
    'problèmes : URI file',
);
T::same(
    '/home/ludo/projet/fichier x.php',
    Diagnostics::localPath('file:///home/ludo/projet/fichier%20x.php'),
    'problèmes : URI décodée',
);

$diagnostics = new Diagnostics();
$diagnostics->update('file:///tmp/cyonima_diag_a.php', [
    [
        'range' => ['start' => ['line' => 0, 'character' => 0]],
        'severity' => 3,
        'message' => 'Information',
        'source' => 'test',
    ],
]);
$diagnostics->update('file:///tmp/cyonima_diag_b.php', [
    [
        'range' => ['start' => ['line' => 4, 'character' => 2]],
        'severity' => 2,
        'message' => "Attention\nmulti-ligne",
        'source' => 'test',
    ],
    [
        'range' => ['start' => ['line' => 1, 'character' => 0]],
        'severity' => 1,
        'message' => 'Boom',
        'source' => 'test',
    ],
    [
        'range' => ['start' => ['line' => 2, 'character' => 0]],
        'severity' => 9,
        'message' => 'Indication',
    ],
]);

T::same(4, $diagnostics->count(), 'problèmes : diagnostic sans message ignoré');
$allDiagnostics = $diagnostics->all();
T::same('/tmp/cyonima_diag_a.php', $allDiagnostics[0]['path'], 'problèmes : tri par fichier');
T::same(1, $allDiagnostics[1]['line'], 'problèmes : tri par ligne');
T::same('Indication', $allDiagnostics[2]['message'], 'problèmes : message conservé');
T::same(3, $allDiagnostics[2]['severity'], 'problèmes : sévérité inconnue normalisée');
T::same('Attention multi-ligne', $allDiagnostics[3]['message'], 'problèmes : message sur une ligne');
T::same(1, $diagnostics->countSeverity(1), 'problèmes : comptage erreurs');
T::same(1, $diagnostics->countSeverity(2), 'problèmes : comptage avertissements');
T::same('1 erreur, 1 avertissement', $diagnostics->summary(), 'problèmes : résumé');
T::same('Erreur', Diagnostics::severityLabel(1), 'problèmes : libellé erreur');

$diagnostics->update('file:///tmp/cyonima_diag_b.php', []);
T::same(1, $diagnostics->count(), 'problèmes : fichier vidé retiré');
$diagnostics->clear();
T::same(0, $diagnostics->count(), 'problèmes : remise à zéro');
T::same('Aucun problème', $diagnostics->summary(), 'problèmes : résumé vide');

$problemOpened = null;
$problemsPanel = new ProblemsPanel(
    function (string $path, int $line) use (&$problemOpened): void {
        $problemOpened = [$path, $line];
    },
);
$problemsPanel->set([
    [
        'path' => '/tmp/cyonima_diag.php',
        'line' => 4,
        'column' => 0,
        'severity' => 1,
        'message' => 'Boom',
        'source' => 'test',
    ],
    [
        'path' => '/tmp/cyonima_diag.php',
        'line' => 1,
        'column' => 3,
        'severity' => 2,
        'message' => 'Attention',
        'source' => 'test',
    ],
]);

T::same(2, $problemsPanel->count(), 'problèmes UI : liste');
$problemsPanel->activateRow(0);
T::same('/tmp/cyonima_diag.php', $problemOpened[0] ?? null, 'problèmes UI : fichier ouvert');
T::same(5, $problemOpened[1] ?? null, 'problèmes UI : ligne base 1');
$problemsPanel->clear();
T::same(0, $problemsPanel->count(), 'problèmes UI : liste vidée');

// ---------------------------------------------------------------------------
T::section('70. IDE — panneau Sortie');

$outputLog = new OutputLog();
$outputLog->append("Ligne 1\nLigne 2", 'Test');
$outputLog->append('');
$outputLog->append('Ligne 3');
T::same(3, $outputLog->count(), 'sortie : lignes ajoutées');
T::ok(
    str_contains($outputLog->text(), '[Test] Ligne 1'),
    'sortie : canal et horodatage',
);
T::ok(
    str_contains($outputLog->text(), 'Ligne 3'),
    'sortie : canal optionnel',
);
T::ok(
    !str_contains($outputLog->text(), 'Ligne 0'),
    'sortie : lignes vides ignorées',
);
$outputLog->clear();
T::same(0, $outputLog->count(), 'sortie : journal vidé');
T::same('', $outputLog->text(), 'sortie : texte vide');

$outputPanel = new OutputPanel();
$outputPanel->append('Bonjour', 'Auto');
T::same(1, $outputPanel->count(), 'sortie UI : ajout');
T::ok(
    str_contains($outputPanel->text(), 'Bonjour'),
    'sortie UI : texte affiché',
);
$outputPanel->clear();
T::same(0, $outputPanel->count(), 'sortie UI : vidage');

// ---------------------------------------------------------------------------
T::section('71. Git — dépôt local');

if (trim((string) shell_exec('command -v git 2>/dev/null')) === '') {
    T::skip('git : commande disponible');
    T::skip('git : statut, index et commit');
} else {
    $gitRoot = sys_get_temp_dir() . '/cyonima_git_' . getmypid();
    $removeTree($gitRoot);
    mkdir($gitRoot, 0775, true);

    $git = function (string $arguments) use ($gitRoot): string {
        return (string) shell_exec(
            'git -C ' . escapeshellarg($gitRoot) . ' ' . $arguments . ' 2>&1',
        );
    };

    $git('init -q');
    $git('config user.email "test@example.com"');
    $git('config user.name "Test Cyonima"');
    file_put_contents($gitRoot . '/a.txt', "one\n");

    $repository = GitRepository::discover($gitRoot);
    T::ok($repository !== null, 'git : dépôt découvert');
    T::same(realpath($gitRoot), $repository?->root(), 'git : racine du dépôt');
    T::ok($repository?->branch() !== '—', 'git : branche courante');

    $gitStatus = $repository?->status() ?? [];
    T::same(1, count($gitStatus), 'git : fichier non suivi');
    T::same('a.txt', $gitStatus[0]['path'] ?? null, 'git : chemin relatif');
    T::same('?', $gitStatus[0]['index'] ?? null, 'git : état index');
    T::ok(!($gitStatus[0]['staged'] ?? true), 'git : non indexé');
    T::ok(
        str_contains($repository?->fileDiff('a.txt') ?? '', '+one'),
        'git : diff fichier non suivi',
    );

    T::ok($repository?->stageAll() ?? false, 'git : indexation globale');
    $gitStatus = $repository?->status() ?? [];
    T::ok($gitStatus[0]['staged'] ?? false, 'git : fichier indexé');
    T::ok(
        str_contains($repository?->diff(null, true) ?? '', '+one'),
        'git : diff indexé',
    );

    $gitCommit = $repository?->commit('initial') ?? ['ok' => false, 'output' => ''];
    T::ok($gitCommit['ok'], 'git : commit créé');
    T::same(0, count($repository?->status() ?? []), 'git : statut propre');
    T::ok(!($repository?->commit('')['ok'] ?? true), 'git : message vide refusé');

    $originalBranch = $repository?->branch() ?? '';
    T::ok(
        in_array($originalBranch, $repository?->branches() ?? [], true),
        'git : branche listée',
    );
    $branchCreated = $repository?->createBranch('feature') ?? ['ok' => false, 'output' => ''];
    T::ok($branchCreated['ok'], 'git : création de branche');
    T::same('feature', $repository?->branch(), 'git : branche active après création');
    T::ok(
        in_array('feature', $repository?->branches() ?? [], true),
        'git : nouvelle branche listée',
    );
    $branchBack = $repository?->switchBranch($originalBranch) ?? ['ok' => false, 'output' => ''];
    T::ok($branchBack['ok'], 'git : bascule de branche');
    T::same($originalBranch, $repository?->branch(), 'git : branche d’origine restaurée');
    T::ok(
        !($repository?->switchBranch('branche-absente')['ok'] ?? true),
        'git : branche inconnue refusée',
    );
    T::ok(
        !($repository?->createBranch('')['ok'] ?? true),
        'git : nom de branche vide refusé',
    );

    file_put_contents($gitRoot . '/a.txt', "one\ntwo\n");
    T::ok(
        str_contains($repository?->diff() ?? '', '+two'),
        'git : diff non indexé',
    );
    T::ok(
        str_contains($repository?->fileDiff('a.txt') ?? '', '+two'),
        'git : diff fichier',
    );

    $gitOpened = null;
    $gitBranches = [];
    $gitMessages = [];
    $gitDiff = null;
    $gitBranchChanged = null;
    $gitPanel = new GitPanel(
        $gitRoot,
        function (string $path) use (&$gitOpened): void {
            $gitOpened = $path;
        },
        function (string $branch, int $changes) use (&$gitBranches): void {
            $gitBranches[] = [$branch, $changes];
        },
        function (string $message, bool $error) use (&$gitMessages): void {
            $gitMessages[] = [$message, $error];
        },
        function (string $path, string $diff) use (&$gitDiff): void {
            $gitDiff = [$path, $diff];
        },
        function (string $branch) use (&$gitBranchChanged): void {
            $gitBranchChanged = $branch;
        },
    );

    T::same(1, $gitPanel->count(), 'git UI : fichier modifié');
    T::ok($gitPanel->branch() !== '—', 'git UI : branche');
    T::ok(
        in_array('feature', $gitPanel->branches(), true),
        'git UI : branches listées',
    );
    T::ok($gitPanel->createBranch('ui-branch')['ok'], 'git UI : création de branche');
    T::same('ui-branch', $gitPanel->branch(), 'git UI : branche créée active');
    T::same('ui-branch', $gitBranchChanged, 'git UI : notification de branche');
    T::ok($gitPanel->switchBranch($originalBranch)['ok'], 'git UI : bascule de branche');
    T::same($originalBranch, $gitPanel->branch(), 'git UI : branche restaurée');
    T::ok(!$gitPanel->switchBranch('branche-absente')['ok'], 'git UI : branche inconnue');
    T::same($gitPanel, $gitPanel->focusBranchEntry(), 'git UI : focus nouvelle branche');
    $gitPanel->activateRow(0);
    T::same(
        realpath($gitRoot) . '/a.txt',
        $gitOpened,
        'git UI : ouverture du fichier',
    );

    T::ok($gitPanel->showDiff(0), 'git UI : diff demandé');
    T::same('a.txt', $gitDiff[0] ?? null, 'git UI : chemin du diff');
    T::ok(str_contains($gitDiff[1] ?? '', '+two'), 'git UI : contenu du diff');
    T::ok(!$gitPanel->showDiff(99), 'git UI : diff de ligne invalide');

    T::ok($gitPanel->stageRow(0), 'git UI : indexation fichier');
    T::same('M', $gitPanel->entries()[0]['index'] ?? null, 'git UI : état indexé');
    T::ok($gitPanel->stageRow(0), 'git UI : désindexation fichier');
    T::same(' ', $gitPanel->entries()[0]['index'] ?? null, 'git UI : état désindexé');
    T::ok(!$gitPanel->stageRow(99), 'git UI : index de ligne invalide');
    T::ok($gitPanel->stageRow(0), 'git UI : réindexation fichier');

    $gitPanel->setMessage('commit depuis le panneau');
    $uiCommit = $gitPanel->commit();
    T::ok($uiCommit['ok'], 'git UI : commit');
    T::same(0, $gitPanel->count(), 'git UI : liste rafraîchie');
    T::same('', $gitPanel->message(), 'git UI : message vidé');
    T::ok($gitMessages !== [], 'git UI : notification');

    T::ok($repository?->createBranch('merge-feature')['ok'], 'git : branche de fusion');
    file_put_contents($gitRoot . '/a.txt', "one\ntwo\nthree\n");
    T::ok($repository?->stageAll() ?? false, 'git : indexation avant fusion');
    T::ok(($repository?->commit('travail sur la branche')['ok'] ?? false), 'git : commit de branche');
    T::ok(($repository?->switchBranch($originalBranch)['ok'] ?? false), 'git : retour avant fusion');
    file_put_contents($gitRoot . '/b.txt', "base\n");
    T::ok($repository?->stageAll() ?? false, 'git : indexation originale');
    T::ok(($repository?->commit('travail original')['ok'] ?? false), 'git : commit original');

    $repositoryMerge = $repository?->merge('merge-feature') ?? ['ok' => false, 'output' => ''];
    T::ok($repositoryMerge['ok'], 'git : fusion de branche');
    T::ok(
        str_contains((string) file_get_contents($gitRoot . '/a.txt'), 'three'),
        'git : contenu fusionné',
    );
    T::same(0, count($repository?->status() ?? []), 'git : statut propre après fusion');
    T::ok(
        !($repository?->merge('branche-absente')['ok'] ?? true),
        'git : fusion avec branche inconnue refusée',
    );

    T::ok($repository?->createBranch('ui-feature')['ok'] ?? false, 'git UI : branche de fusion');
    file_put_contents($gitRoot . '/ui.txt', "ui\n");
    $repository?->stageAll();
    $repository?->commit('fonctionnalité UI');
    $repository?->switchBranch($originalBranch);

    T::same(
        $gitPanel,
        $gitPanel->setMergeBranch('ui-feature'),
        'git UI : champ de fusion',
    );
    $uiMerge = $gitPanel->mergeBranch('ui-feature');
    T::ok($uiMerge['ok'], 'git UI : fusion de branche');
    T::same(0, $gitPanel->count(), 'git UI : statut après fusion');
    T::ok(!$gitPanel->mergeBranch('branche-absente')['ok'], 'git UI : fusion inconnue');

    T::ok($repository?->createBranch('conflict-feature')['ok'] ?? false, 'git : branche de conflit');
    file_put_contents($gitRoot . '/conflict.txt', "version fonctionnalité\n");
    $repository?->stageAll();
    $repository?->commit('version fonctionnalité');
    $repository?->switchBranch($originalBranch);
    file_put_contents($gitRoot . '/conflict.txt', "version originale\n");
    $repository?->stageAll();
    $repository?->commit('version originale');

    $conflictMerge = $repository?->merge('conflict-feature') ?? ['ok' => true, 'output' => ''];
    T::ok(!$conflictMerge['ok'], 'git : fusion en conflit détectée');
    T::ok($repository?->mergeInProgress() ?? false, 'git : merge en cours');
    $conflicts = $repository?->conflicts() ?? [];
    T::same(1, count($conflicts), 'git : fichier en conflit');
    T::same('conflict.txt', $conflicts[0]['path'] ?? null, 'git : chemin en conflit');
    T::ok($conflicts[0]['conflict'] ?? false, 'git : état de conflit');

    $gitPanel->refresh();
    T::same(1, $gitPanel->conflictCount(), 'git UI : conflits détectés');
    T::ok($gitPanel->mergeInProgress(), 'git UI : merge en cours');
    file_put_contents($gitRoot . '/conflict.txt', "résolu\n");
    T::ok($gitPanel->resolve('conflict.txt'), 'git UI : résolution de conflit');
    T::same(0, $gitPanel->conflictCount(), 'git UI : conflits résolus');
    T::ok($gitPanel->mergeInProgress(), 'git UI : merge à terminer');
    $gitPanel->setMessage('fusion résolue');
    $resolvedCommit = $gitPanel->commit();
    T::ok($resolvedCommit['ok'], 'git UI : commit de fusion');
    T::ok(!$gitPanel->mergeInProgress(), 'git UI : merge terminé');
    T::same(0, $gitPanel->count(), 'git UI : statut propre après résolution');

    $repository?->createBranch('abort-feature');
    file_put_contents($gitRoot . '/conflict.txt', "abandon fonctionnalité\n");
    $repository?->stageAll();
    $repository?->commit('abandon fonctionnalité');
    $repository?->switchBranch($originalBranch);
    file_put_contents($gitRoot . '/conflict.txt', "abandon original\n");
    $repository?->stageAll();
    $repository?->commit('abandon original');
    $repository?->merge('abort-feature');
    $gitPanel->refresh();
    T::same(1, $gitPanel->conflictCount(), 'git UI : conflit à abandonner');
    $abortResult = $gitPanel->abortMerge();
    T::ok($abortResult['ok'], 'git UI : merge abandonné');
    T::ok(!$gitPanel->mergeInProgress(), 'git UI : merge interrompu');
    T::same(0, $gitPanel->conflictCount(), 'git UI : conflits nettoyés');

    $noRepositoryPanel = new GitPanel(
        sys_get_temp_dir() . '/cyonima_git_absent_' . getmypid(),
        static function (string $path): void {
        },
    );
    T::same(0, $noRepositoryPanel->count(), 'git UI : dépôt absent');
    T::same('—', $noRepositoryPanel->branch(), 'git UI : branche absente');
    T::ok(!$noRepositoryPanel->commit()['ok'], 'git UI : commit sans dépôt');

    $diffPanel = new DiffPanel();
    $diffPanel->show('src/a.php', "--- a/src/a.php\n+++ b/src/a.php\n@@ -1 +1 @@\n-old\n+new");
    T::same('Diff — src/a.php', $diffPanel->title(), 'diff : titre');
    T::ok(str_contains($diffPanel->text(), '+new'), 'diff : contenu affiché');
    $diffPanel->clear();
    T::same('Aucun diff sélectionné', $diffPanel->title(), 'diff : remise à zéro');
    T::same('', $diffPanel->text(), 'diff : texte vidé');

    $removeTree($gitRoot);
}

// ---------------------------------------------------------------------------
T::section('72. IDE — recherche de symboles');

$symbolRoot = sys_get_temp_dir() . '/cyonima_symbols_' . getmypid();
$removeTree($symbolRoot);
mkdir($symbolRoot . '/src', 0775, true);
mkdir($symbolRoot . '/vendor', 0775, true);
file_put_contents($symbolRoot . '/src/Demo.php', <<<'PHP'
<?php
namespace App;

class Demo
{
    public function run(): void
    {
    }

    private function helper(): void
    {
    }
}

interface Contract
{
}

trait Reusable
{
}

enum Status
{
    case Active;
}

function globalHelper(): void
{
}
PHP);
file_put_contents($symbolRoot . '/vendor/Ignored.php', "<?php\nclass VendorIgnored {}\n");

$symbols = SymbolIndex::scan($symbolRoot);
T::same(7, count($symbols), 'symboles : nombre de définitions');
$symbolNames = array_column($symbols, 'name');
T::ok(in_array('App\\Demo', $symbolNames, true), 'symboles : classe avec espace de noms');
T::ok(in_array('App\\Demo::run', $symbolNames, true), 'symboles : méthode');
T::ok(in_array('App\\Contract', $symbolNames, true), 'symboles : interface');
T::ok(in_array('App\\Reusable', $symbolNames, true), 'symboles : trait');
T::ok(in_array('App\\Status', $symbolNames, true), 'symboles : énumération');
T::ok(in_array('App\\globalHelper', $symbolNames, true), 'symboles : fonction');
T::ok(!in_array('VendorIgnored', $symbolNames, true), 'symboles : vendor exclu');

$classSymbol = null;
foreach ($symbols as $symbol) {
    if ($symbol['name'] === 'App\\Demo') {
        $classSymbol = $symbol;
        break;
    }
}
T::same('class', $classSymbol['kind'] ?? null, 'symboles : type classe');
T::same('src/Demo.php', $classSymbol['path'] ?? null, 'symboles : chemin relatif');
T::same(4, $classSymbol['line'] ?? null, 'symboles : numéro de ligne');
T::same('méthode', SymbolIndex::kindLabel('method'), 'symboles : libellé de type');

$fallbackMethod = new \ReflectionMethod(SymbolIndex::class, 'scanCodeFallback');
$fallbackSymbols = $fallbackMethod->invoke(null, <<<'PHP'
<?php
namespace Fallback;

class Sample
{
    public function method(): void
    {
    }
}

function helper(): void
{
}
PHP);
$fallbackNames = array_column($fallbackSymbols, 'name');
T::ok(in_array('Fallback\\Sample', $fallbackNames, true), 'symboles : repli sans tokenizer (classe)');
T::ok(in_array('Fallback\\Sample::method', $fallbackNames, true), 'symboles : repli sans tokenizer (méthode)');
T::ok(in_array('Fallback\\helper', $fallbackNames, true), 'symboles : repli sans tokenizer (fonction)');

$incrementalRoot = sys_get_temp_dir() . '/cyonima_symbols_inc_' . getmypid();
$removeTree($incrementalRoot);
mkdir($incrementalRoot, 0775, true);
file_put_contents($incrementalRoot . '/A.php', "<?php\nclass A {}\n");
file_put_contents($incrementalRoot . '/B.php', "<?php\nclass B {}\n");

$incremental = new SymbolIndex($incrementalRoot);
T::same(2, count($incremental->refresh()), 'indexation : premier scan');
T::same(2, $incremental->stats()['files'], 'indexation : fichiers en cache');
T::same(2, $incremental->stats()['scanned'], 'indexation : fichiers analysés');

T::same(2, count($incremental->refresh()), 'indexation : second scan');
T::same(0, $incremental->stats()['scanned'], 'indexation : fichiers inchangés ignorés');

file_put_contents($incrementalRoot . '/A.php', "<?php\nclass A {}\nclass C {}\n");
T::same(3, count($incremental->refresh()), 'indexation : fichier modifié réanalysé');
T::same(1, $incremental->stats()['scanned'], 'indexation : seul le fichier modifié est analysé');

unlink($incrementalRoot . '/B.php');
T::same(2, count($incremental->refresh()), 'indexation : fichier supprimé retiré');
T::same(1, $incremental->stats()['files'], 'indexation : cache nettoyé');

$incremental->invalidate($incrementalRoot . '/A.php');
T::same(2, count($incremental->refresh()), 'indexation : invalidation ciblée');
T::same(1, $incremental->stats()['scanned'], 'indexation : fichier invalidé réanalysé');
$removeTree($incrementalRoot);

$symbolWindow = new UI\Window('Recherche de symboles');
$symbolWindow->present();

$symbolOpened = null;
$symbolSearch = new SymbolSearch(
    $symbolWindow,
    $symbolRoot,
    function (string $path, int $line) use (&$symbolOpened): void {
        $symbolOpened = [$path, $line];
    },
);

T::same(7, $symbolSearch->filter(), 'symboles UI : liste');
T::same(1, $symbolSearch->setQuery('global')->count(), 'symboles UI : filtre');
T::same(1, $symbolSearch->choose(0), 'symboles UI : sélection');
T::same('src/Demo.php', $symbolOpened[0] ?? null, 'symboles UI : fichier');
T::same(28, $symbolOpened[1] ?? null, 'symboles UI : ligne');
T::ok(!$symbolSearch->visible(), 'symboles UI : popover fermé');

$symbolWindow->raw()->close();
$removeTree($symbolRoot);

// ---------------------------------------------------------------------------
T::section('73. IDE — surveillance des fichiers');

if (!FileWatcher::available()) {
    T::skip('surveillance : Gio disponible');
    T::skip('surveillance : événement de fichier');
    T::skip('surveillance : moniteurs récursifs');
} else {
    T::ok(true, 'surveillance : Gio disponible');

    $watchRoot = sys_get_temp_dir() . '/cyonima_watch_' . getmypid();
    $removeTree($watchRoot);
    mkdir($watchRoot . '/src', 0775, true);
    mkdir($watchRoot . '/vendor', 0775, true);
    $watchedFile = $watchRoot . '/src/watched.php';
    file_put_contents($watchedFile, "<?php\n");

    $watchEvents = [];
    $fileWatcher = FileWatcher::file(
        $watchedFile,
        function (string $path, int $event) use (&$watchEvents): void {
            $watchEvents[] = [$path, $event];
            Loop::quit();
        },
    );

    if ($fileWatcher === null) {
        T::skip('surveillance : moniteur de fichier');
    } else {
        Loop::after(100, fn () => file_put_contents($watchedFile, "<?php\n// changé\n"));
        Loop::after(3000, fn () => Loop::quit());
        Loop::run();

        T::ok($watchEvents !== [], 'surveillance : événement de fichier');
        T::same(realpath($watchedFile), $watchEvents[0][0] ?? null, 'surveillance : chemin');
        $fileWatcher->cancel();
    }

    $workspaceWatcher = new WorkspaceWatcher(
        $watchRoot,
        static function (array $paths): void {
        },
    );
    T::same(2, $workspaceWatcher->watchCount(), 'surveillance : moniteurs récursifs');
    $workspaceWatcher->cancel();

    $removeTree($watchRoot);
}

// ---------------------------------------------------------------------------
T::section('74. IDE — rechercher et remplacer dans l’éditeur');

$findText = "Needle needle needleX\nNEEDLE";
T::same(4, count(TextSearch::matches($findText, 'needle')), 'éditeur : recherche');
T::same(2, count(TextSearch::matches($findText, 'needle', ['caseSensitive' => true])), 'éditeur : casse');
T::same(3, count(TextSearch::matches($findText, 'needle', ['wholeWord' => true])), 'éditeur : mot entier');
T::same(4, count(TextSearch::matches($findText, 'n[a-z]+dle', ['regex' => true])), 'éditeur : expression régulière');
T::ok(
    !str_contains(TextSearch::replaceAll($findText, 'needle', 'thread'), 'needle'),
    'éditeur : remplacement global',
);
T::same(
    'bar-foo',
    TextSearch::replaceMatch('foobar', '(foo)(bar)', '$2-$1', ['regex' => true]),
    'éditeur : références arrière',
);
T::same(
    '$1',
    TextSearch::replaceMatch('foo', 'foo', '$1'),
    'éditeur : dollar littéral',
);

try {
    TextSearch::matches($findText, '(', ['regex' => true]);
    T::ok(false, 'éditeur : expression invalide signalée');
} catch (\InvalidArgumentException) {
    T::ok(true, 'éditeur : expression invalide signalée');
}

$findEditor = new CodeEditor('php', "one two one\nAlpha");
$findHistoryFile = sys_get_temp_dir() . '/cyonima_find_history_' . getmypid() . '.json';
@unlink($findHistoryFile);
$findHistory = new SearchHistory($findHistoryFile);
$findBar = new FindBar($findEditor, $findHistory);

T::same(2, $findBar->setQuery('one')->matchCount(), 'éditeur UI : occurrences');
T::ok($findBar->findNext(), 'éditeur UI : suivant');
T::same(0, $findBar->currentIndex(), 'éditeur UI : première occurrence');
T::ok($findBar->findNext(), 'éditeur UI : suivant 2');
T::same(1, $findBar->currentIndex(), 'éditeur UI : seconde occurrence');
T::ok($findBar->findPrevious(), 'éditeur UI : précédent');
T::same(0, $findBar->currentIndex(), 'éditeur UI : retour première');

$findBar->setQuery('one')->setReplacement('three');
T::ok($findBar->replaceCurrent(), 'éditeur UI : remplacer');
T::same("three two one\nAlpha", $findEditor->text(), 'éditeur UI : texte remplacé');
T::same(1, $findBar->matchCount(), 'éditeur UI : occurrences restantes');
T::same(1, $findBar->replaceAll(), 'éditeur UI : tout remplacer');
T::same("three two three\nAlpha", $findEditor->text(), 'éditeur UI : texte final');

$findBar->setQuery('ALPHA');
T::same(1, $findBar->matchCount(), 'éditeur UI : casse insensible par défaut');
$findBar->setOption('caseSensitive', true);
T::same(0, $findBar->matchCount(), 'éditeur UI : casse sensible');
$findBar->setOption('caseSensitive', false);
T::same(1, $findBar->matchCount(), 'éditeur UI : retour insensible');

$findEditor->setText("abc-def ghi-jkl");
$findBar->setQuery('(\w+)-(\w+)');
$findBar->setOption('regex', true);
$findBar->setReplacement('$2/$1');
T::same(2, $findBar->replaceAll(), 'éditeur UI : remplacement regex');
T::same("def/abc jkl/ghi", $findEditor->text(), 'éditeur UI : références arrière appliquées');
$findBar->setOption('regex', false);
$findEditor->setText("three two three\nAlpha");

$historyFile = sys_get_temp_dir() . '/cyonima_history_' . getmypid() . '.json';
@unlink($historyFile);
$historyEditor = new CodeEditor('php', "alpha beta");
$historyHistory = new SearchHistory($historyFile);
$historyWindow = new UI\Window('Historique de recherches');
$historyWindow->present();
$historyBar = new FindBar($historyEditor, $historyHistory, $historyWindow);
$historyBar->setQuery('alpha')->findNext();
$historyBar->setQuery('beta')->findNext();
T::same(['beta', 'alpha'], $historyHistory->all(), 'historique : requêtes mémorisées');
$historyBar->historyPrevious();
T::same('beta', $historyBar->query(), 'historique : plus récente');
$historyBar->historyPrevious();
T::same('alpha', $historyBar->query(), 'historique : précédente');
$historyBar->historyNext();
T::same('beta', $historyBar->query(), 'historique : suivante');
$reloadedHistory = new SearchHistory($historyFile);
T::same(['beta', 'alpha'], $reloadedHistory->all(), 'historique : persistance');
$reloadedHistory->add('alpha');
T::same(['alpha', 'beta'], $reloadedHistory->all(), 'historique : déduplication');
$historyBar->showHistory();
$historyBar->dismissHistory();
$historyBar->clearHistory();
T::same(0, $historyHistory->count(), 'historique : vidage');
T::same(0, (new SearchHistory($historyFile))->count(), 'historique : vidage persistant');
$historyWindow->raw()->close();
@unlink($historyFile);

$findWindow = new UI\Window('Recherche éditeur');
$findWindow->setChild($findBar);
$findWindow->present();

$findBar->setQuery('three')->show();
$findBuffer = $findEditor->buffer()->handle();
T::ok(
    GtkText::hasTagAtOffset($findBuffer, 'cyonima-find-current', 0),
    'surlignage : occurrence courante',
);
T::ok(
    GtkText::hasTagAtOffset($findBuffer, 'cyonima-find', 10),
    'surlignage : autres occurrences',
);
$findBar->selectMatch(0);
$findBar->findNext();
T::ok(
    GtkText::hasTagAtOffset($findBuffer, 'cyonima-find-current', 10),
    'surlignage : nouvelle occurrence courante',
);
T::ok(
    GtkText::hasTagAtOffset($findBuffer, 'cyonima-find', 0),
    'surlignage : ancienne occurrence',
);
$findBar->hide();
T::ok(
    !GtkText::hasTagAtOffset($findBuffer, 'cyonima-find', 0)
    && !GtkText::hasTagAtOffset($findBuffer, 'cyonima-find-current', 10),
    'surlignage : nettoyé à la fermeture',
);
$findWindow->raw()->close();

// ---------------------------------------------------------------------------
T::section('75. IDE — aperçu Markdown');

$markdown = "# Titre\n\nUn texte **gras** et *italique* avec `code`.\n\n- pomme\n- poire\n\n> citation\n\n[Lien](https://example.com)\n\n```php\necho 1 < 2;\n```\n";
$pango = Markdown::toPango($markdown);
T::ok(str_contains($pango, 'size="xx-large"'), 'markdown : titre');
T::ok(str_contains($pango, '<b>gras</b>'), 'markdown : gras');
T::ok(str_contains($pango, '<i>italique</i>'), 'markdown : italique');
T::ok(str_contains($pango, '<tt>code</tt>'), 'markdown : code inline');
T::ok(str_contains($pango, '• pomme'), 'markdown : liste');
T::ok(str_contains($pango, '<i>citation</i>'), 'markdown : citation');
T::ok(str_contains($pango, '<a href="https://example.com">Lien</a>'), 'markdown : lien');
T::ok(str_contains($pango, 'echo 1 &lt; 2;'), 'markdown : bloc de code échappé');

$unsafe = Markdown::toPango("<script>alert(1)</script>");
T::ok(
    str_contains($unsafe, '&lt;script&gt;') && !str_contains($unsafe, '<script>'),
    'markdown : HTML échappé',
);

$preview = new MarkdownPreview();
$preview->render("# Aperçu\n\nTexte **fort**");
T::ok(str_contains($preview->markup(), 'Aperçu'), 'markdown UI : contenu');
T::ok(str_contains($preview->markup(), '<b>fort</b>'), 'markdown UI : styles');
$preview->render('');
T::ok($preview->markup() === '', 'markdown UI : document vide');

$mapped = Markdown::toPangoMapped("ligne 1\n```php\ncode\n```\nligne 5");
T::same(5, count($mapped['map']), 'markdown : cartographie des lignes');
T::same(1, $mapped['map'][2] ?? null, 'markdown : ligne de code mappée');
T::same(2, $mapped['map'][4] ?? null, 'markdown : ligne après bloc');

$preview->render("ligne 1\n```php\ncode\n```\nligne 5");
T::same(3, $preview->renderedLineCount(), 'markdown UI : lignes rendues');
T::same(2, $preview->previewLineForSourceLine(4), 'markdown UI : source vers aperçu');
T::same(4, $preview->sourceLineForPreviewLine(2), 'markdown UI : aperçu vers source');

// ---------------------------------------------------------------------------
T::section('76. IDE — défilement synchronisé');

$syncWindow = new UI\Window('Défilement synchronisé', 600, 300);
$syncSource = (new UI\ScrolledWindow(
    (new UI\Label(implode("\n", range(1, 300))))->cssClass('output-text'),
))->expand();
$syncTarget = (new UI\ScrolledWindow(
    (new UI\Label(implode("\n", range(1, 200))))->cssClass('output-text'),
))->expand();
$syncWindow->setChild(
    (new UI\Box(Raw\Orientation::HORIZONTAL, 0))->append($syncSource, $syncTarget)->expand(),
);
$syncWindow->present();

$syncActive = false;
$syncTargetValue = null;
$syncKeep = null;

Loop::after(150, function () use (
    $syncSource,
    $syncTarget,
    &$syncActive,
    &$syncTargetValue,
    &$syncKeep,
): void {
    $sourceAdjustment = GtkScroll::vadjustment($syncSource->raw()->handle());
    $targetAdjustment = GtkScroll::vadjustment($syncTarget->raw()->handle());

    if ($sourceAdjustment === null || $targetAdjustment === null) {
        Loop::quit();
        return;
    }

    $syncKeep = new ScrollSync(
        $syncSource->raw()->handle(),
        $syncTarget->raw()->handle(),
    );
    $syncActive = $syncKeep->active();
    $span = GtkScroll::upper($sourceAdjustment)
        - GtkScroll::pageSize($sourceAdjustment)
        - GtkScroll::lower($sourceAdjustment);
    GtkScroll::setValue(
        $sourceAdjustment,
        GtkScroll::lower($sourceAdjustment) + max(0.0, $span) * 0.5,
    );

    Loop::after(120, function () use ($targetAdjustment, &$syncTargetValue): void {
        $syncTargetValue = GtkScroll::value($targetAdjustment);
        Loop::quit();
    });
});
Loop::after(3000, fn () => Loop::quit());
Loop::run();

T::ok($syncActive, 'défilement : synchronisation active');
T::ok(
    $syncTargetValue !== null && $syncTargetValue > 0.0,
    'défilement : position propagée',
);

if ($syncKeep instanceof ScrollSync) {
    $syncKeep->detach();
}

$syncWindow->raw()->close();

$removeTree($templateRoot);

$removeTree($phpRoot);

exit(T::report());
