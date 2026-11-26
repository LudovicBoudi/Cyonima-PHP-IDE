<?php

declare(strict_types=1);

/**
 * Exemple de barre de menus native GMenu / GAction.
 *
 *   bin/cyonima-compile --project=. \
 *       --php=examples/native-menubar.php \
 *       --templates=examples/native-menu-bar.ui \
 *       --emit=elf
 *   ./dist/native-menubar
 */

namespace App;

use Application;
use Cyonima\Templates\NativeMenuBar;

$application = new Application('com.cyonima.menubar.demo');

$onOpen = function (): void {
    echo "Ouverture demandée\n";
};

$onAbout = function (): void {
    echo "Cyonima — menus natifs\n";
};

$onQuit = function () use ($application): void {
    $application->quit();
};

$onToggle = function (bool $checked): void {
    if ($checked) {
        echo "Mode compact activé\n";
    } else {
        echo "Mode compact désactivé\n";
    }
};

$onAlign = function (string $value): void {
    echo 'Alignement : ', $value, "\n";
};

$window = NativeMenuBar::build(
    'Menus natifs',
    true,
    $onOpen,
    $onAbout,
    $onQuit,
    $onToggle,
    $onAlign
);
$window->present();
$application->run();
