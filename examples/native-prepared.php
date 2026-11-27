<?php

declare(strict_types=1);

/**
 * Exemple de requêtes préparées SQLite.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-prepared.php --emit=elf
 *   ./dist/native-prepared
 */

use Cyonima\Database\Connection;

$db = new Connection(':memory:');
$db->exec(
    'CREATE TABLE accounts ('
    . 'id INTEGER PRIMARY KEY, name TEXT, balance REAL, note TEXT)'
);

$insert = $db->prepare(
    'INSERT INTO accounts (name, balance, note) VALUES (?, ?, ?)'
);

$insert->bindString(1, "O'Reilly");
$insert->bindFloat(2, 12.5);
$insert->bindNull(3);
$insert->execute();

$insert->reset();
$insert->bindString(1, 'Alice');
$insert->bindFloat(2, 99.0);
$insert->bindString(3, 'admin');
$insert->execute();
$insert->finalize();

$select = $db->prepare(
    'SELECT name, balance, note FROM accounts WHERE id = ?'
);
$select->bindInt(1, 1);

if ($select->step() === 1) {
    echo $select->columnString(0), ' ', $select->columnFloat(1), "\n";
    $row = $select->row();
    echo $row['name'], ' note=', $row['note'], "\n";
}

$select->finalize();

$count = $db->prepare('SELECT COUNT(*) FROM accounts');

if ($count->step() === 1) {
    echo 'comptes=', $count->columnInt(0), "\n";
}

$count->finalize();
$db->close();
