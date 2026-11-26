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

$count = 0;

$increment = function () use (&$count): void {
    $count = $count + 1;
};

$increment();
$increment();

echo $multiply(4), ' ', $add(4), ' ', $count, "\n";
