<?php

declare(strict_types=1);

/**
 * Exemple de jointures typées : INNER JOIN et LEFT JOIN.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-joins.php --emit=elf
 *   ./dist/native-joins
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
$users->insert(['name' => 'Carol']);

$tasks = new Table($db, 'tasks');
$tasks->insert(['user_id' => '1', 'title' => 'Compilateur']);
$tasks->insert(['user_id' => '1', 'title' => 'Templates']);
$tasks->insert(['user_id' => '2', 'title' => 'ORM']);

$statement = $users->query()
    ->join('tasks', 'tasks.user_id', 'users.id')
    ->selectAs('users.name', 'user_name')
    ->selectAs('tasks.title', 'task_title')
    ->orderBy('users.id', false)
    ->get();
$rows = 0;

while ($statement->step() === 1) {
    $row = $statement->row();
    echo $row['user_name'], ' / ', $row['task_title'], "\n";
    $rows = $rows + 1;
}

$statement->finalize();
echo 'inner=', $rows, "\n";

$statement = $users->query()
    ->leftJoin('tasks', 'tasks.user_id', 'users.id')
    ->selectAs('users.name', 'user_name')
    ->selectAs('tasks.title', 'task_title')
    ->orderBy('users.id', false)
    ->get();
$rows = 0;

while ($statement->step() === 1) {
    $row = $statement->row();
    $title = $row['task_title'];

    if ($title === '') {
        $title = '—';
    }

    echo $row['user_name'], ' / ', $title, "\n";
    $rows = $rows + 1;
}

$statement->finalize();
echo 'left=', $rows, "\n";

$query = $users->query()
    ->join('tasks', 'tasks.user_id', 'users.id')
    ->whereString('tasks.title', 'LIKE', 'T%');
echo 'filtered=', $query->count(), "\n";

try {
    $users->query()->join('tasks; DROP', 'tasks.user_id', 'users.id');
    echo "unexpected\n";
} catch (RuntimeException $error) {
    echo 'guard=', $error->getMessage(), "\n";
}

$db->close();
