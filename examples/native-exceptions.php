<?php

declare(strict_types=1);

/**
 * Exemple d’exceptions compilées.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-exceptions.php --emit=elf
 *   ./dist/native-exceptions
 */

function double(int $value): int
{
    if ($value < 0) {
        throw 'valeur négative';
    }

    return $value * 2;
}

try {
    echo double(3), "\n";
    echo double(-1), "\n";
} catch (string $error) {
    echo 'Erreur : ', $error, "\n";
}
