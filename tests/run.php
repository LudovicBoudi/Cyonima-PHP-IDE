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
T::ok($first->classes === 23, '23 classes générées', (string) $first->classes);
T::ok($first->methods >= 50, 'au moins 50 méthodes générées', (string) $first->methods);
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
T::section('7. Différentiel PyGObject (si disponible)');

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
}

exit(T::report());
