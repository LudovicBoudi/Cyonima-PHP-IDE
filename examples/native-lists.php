<?php

declare(strict_types=1);

/**
 * Exemple de listes GTK : modèle de chaînes, DropDown et ListBox.
 *
 *   bin/cyonima-compile --project=. \
 *       --php=examples/native-lists.php \
 *       --templates=examples/native-lists.ui \
 *       --emit=elf
 *   ./dist/native-lists
 */

namespace App;

use Application;
use Cyonima\Templates\NativeLists;

$options = ['Tous', 'Ouverts', 'Fermés'];

$onSelect = function (int $index): void {
    echo 'sélection ', $index, "\n";
};

$application = new Application('com.cyonima.lists.demo');
$window = NativeLists::build($options, 0, $onSelect);
$window->present();
$application->run();
