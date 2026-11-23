<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

use Cyonima\Gtk\Raw\Align;
use Cyonima\Gtk\Raw\Orientation;
use Cyonima\Runtime\Application;
use Cyonima\Runtime\Loop;
use Cyonima\UI\Box;
use Cyonima\UI\Button;
use Cyonima\UI\Label;

$auto = in_array('--auto', $argv, true);

$app = new Application('com.cyonima.counter');

$app->addCss(<<<'CSS'
window { background: #101418; }
.card { background: #1b2027; border-radius: 16px; padding: 24px; }
.count { color: #3ddc97; font-size: 64px; font-weight: 800; }
.hint { color: #7d8794; font-size: 12px; }
button { background: #3ddc97; color: #06231a; border-radius: 10px; padding: 10px 22px; font-weight: 700; }
CSS);

$window = $app->window('Cyonima — compteur natif', 520, 300);

$count = 0;

$counter = (new Label('0'))->cssClass('count')->align(Align::CENTER);
$hint = (new Label('Cliquez : le handler est une closure PHP appelée par GTK'))
    ->cssClass('hint')
    ->wrap()
    ->xalign(0.5);

$button = (new Button('Incrémenter'))->onClicked(function () use (&$count, $counter): void {
    $count++;
    $counter->text((string) $count);
});

$box = (new Box(Orientation::VERTICAL, 14))
    ->append($counter, $hint, $button)
    ->cssClass('card')
    ->margin(28);

// La boucle s'arrête seule à la fermeture de la dernière fenêtre.
$window->setChild($box)->present();

if ($auto) {
    Loop::after(300, fn () => $button->click());
    Loop::after(450, fn () => $button->click());
    Loop::after(600, fn () => $button->click());
    Loop::after(1400, fn () => $window->raw()->close());
}

$app->run();

printf("Compteur final : %d (affiché : %s)\n", $count, $counter->getText());
