<?php

declare(strict_types=1);

/**
 * Exemple de module natif SQLite compilé.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-sqlite.php \
 *       --emit=elf --module=sqlite
 *   ./dist/native-sqlite
 */

$db = sqlite_open(':memory:');

if ($db === 0) {
    throw 'ouverture SQLite impossible';
}

sqlite_exec($db, 'CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT, price REAL)');
sqlite_exec($db, "INSERT INTO items (name, price) VALUES ('alpha', 1.5)");
sqlite_exec($db, "INSERT INTO items (name, price) VALUES ('beta', 2.5)");

echo sqlite_query_int($db, 'SELECT count(*) FROM items'), ' ', sqlite_last_id($db), "\n";
echo sqlite_query_string($db, 'SELECT name FROM items ORDER BY id LIMIT 1'), "\n";
echo sqlite_query_float($db, 'SELECT price FROM items WHERE id = 2'), "\n";

sqlite_exec($db, 'UPDATE items SET price = 3.0 WHERE id = 1');
echo sqlite_changes($db), "\n";

sqlite_close($db);
