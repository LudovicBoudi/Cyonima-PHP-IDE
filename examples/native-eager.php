<?php

declare(strict_types=1);

/**
 * Exemple de eager loading simple : une requête pour N identifiants.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-eager.php --emit=elf
 *   ./dist/native-eager
 */

use Cyonima\Database\Connection;
use Cyonima\Database\Table;

$db = new Connection(':memory:');
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
$db->exec(
    'CREATE TABLE tasks ('
    . 'id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT)'
);

$users = new Table($db, 'users');
$users->insert(['name' => 'Alice']);
$users->insert(['name' => 'Bob']);

$tasks = new Table($db, 'tasks');
$tasks->insert(['user_id' => '1', 'title' => 'Première']);
$tasks->insert(['user_id' => '1', 'title' => 'Deuxième']);
$tasks->insert(['user_id' => '2', 'title' => 'Troisième']);

$owners = $users->findMany($users->ids());
$names = [];

while ($owners->step() === 1) {
    $row = $owners->row();
    $names[$row['id']] = $row['name'];
}

$owners->finalize();
echo 'utilisateurs=', count($names), ' ', $names['1'], ' ', $names['2'], "\n";

$statement = $tasks->whereIn('user_id', $users->ids());
$count = 0;

while ($statement->step() === 1) {
    $count = $count + 1;
}

$statement->finalize();
echo 'tâches=', $count, "\n";

$relation = $users->hasMany('tasks', 'user_id', 1);
$statement = $relation->statement();
$titles = [];

while ($statement->step() === 1) {
    $row = $statement->row();
    $titles[] = $row['title'];
}

$statement->finalize();
echo 'tâches Alice=', count($titles), "\n";

foreach ($titles as $title) {
    echo '- ', $title, "\n";
}

$empty = $users->findMany([]);
$emptyCount = 0;

while ($empty->step() === 1) {
    $emptyCount = $emptyCount + 1;
}

$empty->finalize();
echo 'vide=', $emptyCount, "\n";

$db->close();
