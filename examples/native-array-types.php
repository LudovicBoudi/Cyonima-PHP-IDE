<?php

declare(strict_types=1);

/**
 * Exemple de tableaux typés dans les signatures.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-array-types.php --emit=elf
 *   ./dist/native-array-types
 */

/**
 * @return array<int>
 */
function build(int $size): array
{
    $values = [];
    $i = 0;

    while ($i < $size) {
        $values[] = $i * 10;
        $i = $i + 1;
    }

    return $values;
}

/**
 * @param array<int> $values
 */
function total(array $values): int
{
    $sum = 0;

    foreach ($values as $value) {
        $sum = $sum + $value;
    }

    return $sum;
}

/**
 * @param array<string,int> $table
 */
function lookup(array $table, string $key): int
{
    return $table[$key];
}

$values = build(4);
echo total($values), ' ', count($values), "\n";

$table = ['a' => 1, 'b' => 2];
echo lookup($table, 'b'), "\n";
