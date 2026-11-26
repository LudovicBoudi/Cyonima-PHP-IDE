<?php

declare(strict_types=1);

/**
 * Exemple de base de données native : migrations et repository SQLite.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-database.php --emit=elf
 *   ./dist/native-database
 */

namespace App;

use Cyonima\Database\Connection;
use Cyonima\Database\Migration;
use Cyonima\Database\Migrator;
use Cyonima\Database\Repository;

final class CreateUsers extends Migration
{
    public function version(): int
    {
        return 1;
    }

    public function up(Connection $connection): void
    {
        $connection->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
    }
}

final class User
{
    public int $id;
    public string $name;

    public function __construct(int $id, string $name)
    {
        $this->id = $id;
        $this->name = $name;
    }
}

final class UserRepository extends Repository
{
    public function __construct(Connection $connection)
    {
        parent::__construct($connection, 'users');
    }

    public function create(string $name): User
    {
        $this->execute(
            'INSERT INTO users (name) VALUES (' . $this->connection->quote($name) . ')'
        );

        return new User($this->lastId(), $name);
    }

    public function find(int $id): ?User
    {
        $row = $this->row('SELECT id, name FROM users WHERE id = ' . strval($id));

        if (count($row) === 0) {
            return null;
        }

        return new User(intval($row['id']), $row['name']);
    }
}

$db = new Connection(':memory:');
$migrator = new Migrator($db);
$migrator->add(new CreateUsers());
echo 'migrations: ', $migrator->run(), "\n";

$users = new UserRepository($db);
$user = $users->create("O'Reilly");
echo $user->id, ' ', $user->name, "\n";

$found = $users->find($user->id);

if ($found !== null) {
    echo 'found: ', $found->name, "\n";
}

echo 'count: ', $users->count(), "\n";
$db->close();
