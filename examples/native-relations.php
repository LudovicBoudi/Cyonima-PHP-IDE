<?php

declare(strict_types=1);

/**
 * Exemple de relations ORM explicites : belongsTo et hasMany.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-relations.php --emit=elf
 *   ./dist/native-relations
 */

namespace App;

use Cyonima\Database\Connection;
use Cyonima\Database\Entity;
use Cyonima\Database\Migration;
use Cyonima\Database\Migrator;
use Cyonima\Database\Repository;
use RuntimeException;

final class CreateSchema extends Migration
{
    public function version(): int
    {
        return 1;
    }

    public function up(Connection $connection): void
    {
        $connection->exec(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)'
        );
        $connection->exec(
            'CREATE TABLE todos ('
            . 'id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT)'
        );
    }
}

final class User extends Entity
{
    public string $name;

    public function __construct(string $name)
    {
        parent::__construct();
        $this->name = $name;
    }

    public function table(): string
    {
        return 'users';
    }

    /** @return array<string,string> */
    public function values(): array
    {
        $values = [];
        $values['name'] = $this->name;

        return $values;
    }

    /** @param array<string,string> $row */
    public function hydrate(array $row): void
    {
        $this->id = intval($row['id']);
        $this->name = $row['name'];
    }
}

final class Todo extends Entity
{
    public int $userId;
    public string $title;

    public function __construct(int $userId, string $title)
    {
        parent::__construct();
        $this->userId = $userId;
        $this->title = $title;
    }

    public function table(): string
    {
        return 'todos';
    }

    /** @return array<string,string> */
    public function values(): array
    {
        $values = [];
        $values['user_id'] = strval($this->userId);
        $values['title'] = $this->title;

        return $values;
    }

    /** @param array<string,string> $row */
    public function hydrate(array $row): void
    {
        $this->id = intval($row['id']);
        $this->userId = intval($row['user_id']);
        $this->title = $row['title'];
    }
}

final class UserRepository extends Repository
{
    public function __construct(Connection $connection)
    {
        parent::__construct($connection, 'users');
    }

    public function findOrFail(int $id): User
    {
        $row = $this->store()->find($id);

        if (count($row) === 0) {
            throw new RuntimeException('utilisateur introuvable : ' . strval($id));
        }

        $user = new User('');
        $user->hydrate($row);

        return $user;
    }

    public function save(User $user): int
    {
        return $user->save($this->connection);
    }
}

final class TodoRepository extends Repository
{
    public function __construct(Connection $connection)
    {
        parent::__construct($connection, 'todos');
    }

    public function findOrFail(int $id): Todo
    {
        $row = $this->store()->find($id);

        if (count($row) === 0) {
            throw new RuntimeException('tâche introuvable : ' . strval($id));
        }

        $todo = new Todo(0, '');
        $todo->hydrate($row);

        return $todo;
    }

    public function save(Todo $todo): int
    {
        return $todo->save($this->connection);
    }

    public function owner(Todo $todo): ?User
    {
        $row = $this->belongsTo('users', $todo->userId)->row();

        if (count($row) === 0) {
            return null;
        }

        $user = new User('');
        $user->hydrate($row);

        return $user;
    }

    /** @return array<Todo> */
    public function forUser(int $userId): array
    {
        $ids = $this->hasMany('todos', 'user_id', $userId)->ids();
        $todos = [];

        foreach ($ids as $id) {
            $row = $this->store()->find($id);

            if (count($row) > 0) {
                $todo = new Todo(0, '');
                $todo->hydrate($row);
                $todos[] = $todo;
            }
        }

        return $todos;
    }
}

$db = new Connection(':memory:');
$migrator = new Migrator($db);
$migrator->add(new CreateSchema());
$migrator->run();

$users = new UserRepository($db);
$todos = new TodoRepository($db);

$aliceId = $users->save(new User('Alice'));
$users->save(new User('Bob'));

$todos->save(new Todo($aliceId, 'Première tâche'));
$todos->save(new Todo($aliceId, 'Deuxième tâche'));

$first = $todos->findOrFail(1);
$owner = $todos->owner($first);

if ($owner !== null) {
    echo 'propriétaire : ', $owner->name, "\n";
}

$relation = $todos->hasMany('todos', 'user_id', $aliceId);
echo 'tâches : ', $relation->count(), "\n";

foreach ($todos->forUser($aliceId) as $todo) {
    echo '- ', $todo->title, "\n";
}

$db->close();
