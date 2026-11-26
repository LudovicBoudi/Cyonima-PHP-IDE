<?php

declare(strict_types=1);

/**
 * Exemple d’ORM léger : entité, repository typé, transactions.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-orm.php --emit=elf
 *   ./dist/native-orm
 */

namespace App;

use Cyonima\Database\Connection;
use Cyonima\Database\Entity;
use Cyonima\Database\Migration;
use Cyonima\Database\Migrator;
use Cyonima\Database\Repository;
use RuntimeException;

final class CreateTodos extends Migration
{
    public function version(): int
    {
        return 1;
    }

    public function up(Connection $connection): void
    {
        $connection->exec(
            'CREATE TABLE todos (id INTEGER PRIMARY KEY, title TEXT, done TEXT)'
        );
    }
}

final class Todo extends Entity
{
    public string $title;
    public bool $done;

    public function __construct(string $title, bool $done)
    {
        parent::__construct();
        $this->title = $title;
        $this->done = $done;
    }

    public function table(): string
    {
        return 'todos';
    }

    /** @return array<string,string> */
    public function values(): array
    {
        $values = [];
        $values['title'] = $this->title;

        if ($this->done) {
            $values['done'] = '1';
        } else {
            $values['done'] = '0';
        }

        return $values;
    }

    /** @param array<string,string> $row */
    public function hydrate(array $row): void
    {
        $this->id = intval($row['id']);
        $this->title = $row['title'];
        $this->done = $row['done'] === '1';
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
            throw new RuntimeException('todo introuvable : ' . strval($id));
        }

        $todo = new Todo('', false);
        $todo->hydrate($row);

        return $todo;
    }

    /** @return array<Todo> */
    public function all(): array
    {
        $todos = [];
        $ids = $this->store()->ids();

        foreach ($ids as $id) {
            $row = $this->store()->find($id);

            if (count($row) > 0) {
                $todo = new Todo('', false);
                $todo->hydrate($row);
                $todos[] = $todo;
            }
        }

        return $todos;
    }

    public function save(Todo $todo): int
    {
        return $todo->save($this->connection);
    }
}

$db = new Connection(':memory:');
$migrator = new Migrator($db);
$migrator->add(new CreateTodos());
$migrator->run();

$todos = new TodoRepository($db);
$todos->save(new Todo('Compiler l’ORM', false));
$todos->save(new Todo('Documenter les transactions', true));

$db->transaction(function () use ($todos): void {
    $todos->save(new Todo('Troisième tâche', false));
});

$all = $todos->all();
echo count($all), " tâche(s)\n";

foreach ($all as $todo) {
    if ($todo->done) {
        echo '[x] ', $todo->title, "\n";
    } else {
        echo '[ ] ', $todo->title, "\n";
    }
}

$first = $todos->findOrFail(1);
$first->done = true;
$first->save($db);

if ($todos->findOrFail(1)->done) {
    echo "après mise à jour : fait\n";
} else {
    echo "après mise à jour : à faire\n";
}

$first->delete($db);
echo 'restantes : ', $todos->store()->count(), "\n";

$db->close();
