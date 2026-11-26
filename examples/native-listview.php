<?php

declare(strict_types=1);

/**
 * Exemple de GtkListView lié à un GtkStringList.
 *
 *   bin/cyonima-compile --project=. \
 *       --php=examples/native-listview.php \
 *       --templates=examples/native-listview.ui \
 *       --emit=elf
 *   ./dist/native-listview
 */

namespace App;

use Application;
use Cyonima\Templates\NativeListview;

$application = new Application('com.cyonima.listview.demo');

$options = ['Premier', 'Deuxième', 'Troisième'];

$onActivate = function (int $index): void {
    echo 'ligne activée : ', $index, "\n";
};

$window = NativeListview::build($options, 0, $onActivate);
$window->present();
$application->run();
