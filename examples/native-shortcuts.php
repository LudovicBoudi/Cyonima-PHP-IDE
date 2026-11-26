<?php

declare(strict_types=1);

/**
 * Exemple de raccourcis clavier GTK.
 *
 *   bin/cyonima-compile --project=. \
 *       --php=examples/native-shortcuts.php \
 *       --templates=examples/native-shortcuts.ui \
 *       --emit=elf
 *   ./dist/native-shortcuts
 */

namespace App;

use Application;
use Cyonima\Templates\NativeShortcuts;

$application = new Application('com.cyonima.shortcuts.demo');

$onOpen = function (): void {
    echo "Ctrl+O — ouverture\n";
};

$onQuit = function () use ($application): void {
    echo "Ctrl+Q — fermeture\n";
    $application->quit();
};

$window = NativeShortcuts::build('Raccourcis GTK', $onOpen, $onQuit);
$window->present();
$application->run();
