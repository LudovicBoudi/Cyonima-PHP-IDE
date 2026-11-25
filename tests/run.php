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
use Cyonima\Runtime\Kernel;
use Cyonima\Runtime\Loop;
use Cyonima\Runtime\LspClient;
use Cyonima\Runtime\PhpIntellisense;
use Cyonima\Runtime\Config;
use Cyonima\Compiler\FormCompiler;
use Cyonima\Compiler\Lexer\Lexer;
use Cyonima\Compiler\Lexer\TokenType;
use Cyonima\Compiler\Parser\Parser;
use Cyonima\Compiler\PhpCompiler;
use Cyonima\Runtime\FormDocument;
use Cyonima\Runtime\ProjectFactory;
use Cyonima\Runtime\RecentProjects;
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

$openedByClick = null;
$clickBrowser = new UI\FileBrowser($examples, function (string $path) use (&$openedByClick): void {
    $openedByClick = $path;
});
$clickBrowser->activateRow(0);
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
T::ok(str_contains($phpCSource, 'int main(void)'), 'backend PHP : main natif');
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
    ['tableau associatif', '<?php $a = ["x" => 1];', 'tableaux associatifs non supportés'],
    ['foreach non-tableau', '<?php $x = 1; foreach ($x as $v) {}', 'foreach attend un tableau'],
    ['foreach sans type', '<?php $a = []; foreach ($a as $v) {}', 'type d’élément inconnu'],
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

$removeTree($phpRoot);

exit(T::report());
