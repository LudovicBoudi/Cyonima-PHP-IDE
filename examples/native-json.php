<?php

declare(strict_types=1);

/**
 * Exemple de JSON natif.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-json.php --emit=elf
 *   ./dist/native-json
 */

$payload = [
    'name' => 'alice',
    'city' => 'paris',
];

$json = json_encode($payload);
echo $json, "\n";

$table = json_decode_map_string($json);
echo $table['name'], "\n";

$values = json_decode_array_int('[1, 2, 3]');
echo count($values), ' ', $values[1], "\n";

try {
    json_decode_int('nope');
} catch (string $error) {
    echo 'erreur: ', $error, "\n";
}
