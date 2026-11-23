<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

use Cyonima\Gtk\Raw\Align;
use Cyonima\Gtk\Raw\Orientation;
use Cyonima\Runtime\Application;
use Cyonima\Runtime\Async;
use Cyonima\Runtime\Loop;
use Cyonima\Runtime\Process;
use Cyonima\UI\Box;
use Cyonima\UI\Button;
use Cyonima\UI\Label;

/**
 * Démontre les trois niveaux d'asynchrone :
 *  - un sous-processus de 900 ms (travail réellement bloquant, isolé) ;
 *  - le handler du bouton qui l'attend sans figer l'UI (fibre) ;
 *  - une fibre d'animation qui rafraîchit l'horloge toutes les 100 ms.
 *
 * Pendant la tâche, le bouton « Cliquer » reste utilisable.
 */

$auto = in_array('--auto', $argv, true);

$app = new Application('com.cyonima.async');

$app->addCss(<<<'CSS'
window { background: #101418; }
.card { background: #1b2027; border-radius: 16px; padding: 20px; }
.title { color: #f2f5f8; font-size: 18px; font-weight: 800; }
.status { color: #3ddc97; font-size: 14px; font-weight: 700; }
.hint { color: #7d8794; font-size: 12px; }
button { background: #2b333d; color: #e8edf3; border-radius: 10px; padding: 9px 16px; font-weight: 700; }
button.solid { background: #3ddc97; color: #06231a; }
CSS);

$window = $app->window('Cyonima — asynchrone coopératif', 620, 380);

$status = (new Label('Prêt'))->cssClass('status');
$clock = (new Label('0 ms écoulées'))->cssClass('hint');
$clicks = (new Label('Clics pendant la tâche : 0'))->cssClass('hint');

$elapsed = 0.0;
$clicksCount = 0;
$finished = false;

// Fibre de rafraîchissement : suspend toutes les 100 ms, l'UI continue.
Async::run(function () use (&$elapsed, $clock): void {
    $start = Loop::now();
    while (true) {
        $elapsed = Loop::now() - $start;
        $clock->text(sprintf('%.0f ms écoulées', $elapsed));
        Async::sleep(100);
    }
});

$longTask = (new Button('Lancer une tâche de 900 ms'))->cssClass('solid');
$longTask->onClicked(function () use ($status, &$finished): void {
    $status->text('Tâche en cours… (l\'UI reste utilisable)');

    $startedAt = Loop::now();
    $process = Process::php('usleep(900000); echo "OK";');
    $result = Async::await($process);

    $finished = true;
    $status->text(sprintf(
        'Terminé en %.0f ms — sortie du processus : %s',
        Loop::now() - $startedAt,
        $result->output(),
    ));
});

$clicker = (new Button('Cliquer pendant la tâche'))->onClicked(
    function () use (&$clicksCount, $clicks): void {
        $clicksCount++;
        $clicks->text("Clics pendant la tâche : {$clicksCount}");
    }
);

$header = (new Label('Fibres + sous-processus'))->cssClass('title')->xalign(0.0);

$box = (new Box(Orientation::VERTICAL, 12))
    ->append($header, $status, $clock, $longTask, $clicker, $clicks)
    ->cssClass('card')
    ->margin(24)
    ->align(Align::FILL);

$window->setChild($box)->present();

if ($auto) {
    Loop::after(150, fn () => $longTask->click());
    Loop::after(350, fn () => $clicker->click());
    Loop::after(550, fn () => $clicker->click());
    Loop::after(1700, fn () => $window->raw()->close());
}

$app->run();

printf(
    "Tâche terminée : %s | clics : %d | horloge figée à %.0f ms\n",
    $finished ? 'oui' : 'non',
    $clicksCount,
    $elapsed,
);
