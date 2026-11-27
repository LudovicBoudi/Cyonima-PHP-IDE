<?php

declare(strict_types=1);

/**
 * Exemple de query builder typé au-dessus des requêtes préparées.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-query-builder.php --emit=elf
 *   ./dist/native-query-builder
 */

use Cyonima\Database\Connection;
use Cyonima\Database\Table;

$db = new Connection(':memory:');
$db->exec(
    'CREATE TABLE items ('
    . 'id INTEGER PRIMARY KEY, name TEXT, score INTEGER, '
    . 'ratio REAL, active INTEGER, note TEXT)'
);

$items = new Table($db, 'items');
$items->insert([
    'name' => 'Alice',
    'score' => '10',
    'ratio' => '1.5',
    'active' => '1',
    'note' => '',
]);
$items->insert([
    'name' => 'Bob',
    'score' => '20',
    'ratio' => '2.5',
    'active' => '0',
    'note' => 'yes',
]);
$items->insert([
    'name' => 'Carol',
    'score' => '30',
    'ratio' => '3.5',
    'active' => '1',
    'note' => 'no',
]);

$statement = $items->query()
    ->whereInt('score', '>=', 20)
    ->orderBy('score', true)
    ->limit(1)
    ->get();

if ($statement->step() === 1) {
    $row = $statement->row();
    echo $row['name'], ' ', $row['score'], "\n";
}

$statement->finalize();

$query = $items->query()->whereString('name', 'LIKE', 'A%');
echo 'count=', $query->count(), "\n";

if ($query->exists()) {
    echo "exists=yes\n";
}

$statement = $items->query()
    ->whereBool('active', '=', true)
    ->orderBy('id', false)
    ->get();
$names = [];

while ($statement->step() === 1) {
    $row = $statement->row();
    $names[] = $row['name'];
}

$statement->finalize();
echo 'active=', count($names), ' ', $names[0], ' ', $names[1], "\n";

$statement = $items->query()
    ->whereFloat('ratio', '>', 2.0)
    ->select(['name', 'ratio'])
    ->get();

if ($statement->step() === 1) {
    $row = $statement->row();
    echo 'float=', $row['name'], "\n";
}

$statement->finalize();

$statement = $items->query()
    ->orderBy('id', false)
    ->offset(1)
    ->limit(1)
    ->get();

if ($statement->step() === 1) {
    $row = $statement->row();
    echo 'offset=', $row['name'], "\n";
}

$statement->finalize();
$db->close();
