<?php

declare(strict_types=1);

/**
 * Exemple de menu popover GTK compilé depuis un template.
 *
 *   bin/cyonima-compile --project=. \
 *       --php=examples/native-menu.php \
 *       --templates=examples/native-menu.ui \
 *       --emit=elf
 *   ./dist/native-menu
 */

namespace App;

use Application;
use Cyonima\Templates\NativeMenu;

$application = new Application('com.cyonima.menu.demo');

$onAbout = function (): void {
    echo "Cyonima — menus GTK\n";
};

$onQuit = function () use ($application): void {
    $application->quit();
};

$window = NativeMenu::build('Menus GTK', $onAbout, $onQuit);
$window->present();
$application->run();
