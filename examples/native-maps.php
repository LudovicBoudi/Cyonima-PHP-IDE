<?php

declare(strict_types=1);

/**
 * Exemple de tableaux associatifs compilés.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-maps.php --emit=elf
 *   ./dist/native-maps
 */

$ages = [
    'alice' => 30,
    'bob' => 25,
];

$ages['carol'] = 40;

$total = 0;

foreach ($ages as $name => $age) {
    echo $name, ' : ', $age, "\n";
    $total = $total + $age;
}

echo 'Total : ', $total, "\n";
