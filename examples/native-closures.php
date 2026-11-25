<?php

declare(strict_types=1);

/**
 * Exemple de closures compilées.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-closures.php --emit=elf
 *   ./dist/native-closures
 */

$factor = 3;

$multiply = function (int $value) use ($factor): int {
    return $value * $factor;
};

$add = fn (int $value): int => $value + $factor;

echo $multiply(4), ' ', $add(4), "\n";
