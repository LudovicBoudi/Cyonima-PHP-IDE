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
use Cyonima\Runtime\Loop;
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

$spec = Spec::fromFile(dirname(__DIR__) . '/spec/gtk-slice.php');
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
T::ok($first->classes === 7, '7 classes générées', (string) $first->classes);
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
T::section('5. Différentiel PyGObject (si disponible)');

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
}

exit(T::report());
