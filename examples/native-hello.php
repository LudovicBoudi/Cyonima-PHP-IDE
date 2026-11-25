<?php

declare(strict_types=1);

/**
 * Exemple compilable par le sous-ensemble PHP de Cyonima.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-hello.php --emit=elf
 *   ./dist/native-hello
 *
 * L’interpréteur PHP peut aussi exécuter ce fichier tel quel.
 */

function greet(string $name): string
{
    return 'Bonjour ' . $name;
}

$value = 5;

if ($value > 3) {
    echo greet('Cyonima'), " !\n";
} else {
    echo "valeur trop petite\n";
}
