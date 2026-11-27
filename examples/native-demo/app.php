<?php

declare(strict_types=1);

/**
 * Démonstration complète : template GTK, SQLite, ORM, relations, validation,
 * menu, raccourci, ListView et widgets préconstruits.
 *
 *   bin/cyonima-compile --project=examples/native-demo \
 *       --php=examples/native-demo/app.php --emit=elf
 *   ./examples/native-demo/dist/app
 */

namespace App;

use Application;
use Button;
use Cyonima\Database\Connection;
use Cyonima\Database\Entity;
use Cyonima\Database\Migration;
use Cyonima\Database\Migrator;
use Cyonima\Database\Repository;
use Cyonima\Templates\DemoPage;
use Cyonima\Validation\MinLength;
use Cyonima\Validation\Required;
use Cyonima\Validation\ValidationException;
use Cyonima\Validation\Validator;
use Entry;
use Label;
use Listable;
use ListView;
use ObjectList;
use RuntimeException;
use StringList;

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
            'CREATE TABLE tasks ('
            . 'id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT, done TEXT)'
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

final class Task extends Entity implements Listable
{
    public int $userId;
    public string $title;
    public bool $done;

    public function __construct(int $userId, string $title, bool $done)
    {
        parent::__construct();
        $this->userId = $userId;
        $this->title = $title;
        $this->done = $done;
    }

    public function table(): string
    {
        return 'tasks';
    }

    /** @return array<string,string> */
    public function values(): array
    {
        $values = [];
        $values['user_id'] = strval($this->userId);
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
        $this->userId = intval($row['user_id']);
        $this->title = $row['title'];
        $this->done = $row['done'] === '1';
    }

    public function listLabel(): string
    {
        if ($this->done) {
            return '[x] ' . $this->title;
        }

        return '[ ] ' . $this->title;
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

final class TaskRepository extends Repository
{
    public function __construct(Connection $connection)
    {
        parent::__construct($connection, 'tasks');
    }

    public function findOrFail(int $id): Task
    {
        $row = $this->store()->find($id);

        if (count($row) === 0) {
            throw new RuntimeException('tâche introuvable : ' . strval($id));
        }

        $task = new Task(0, '', false);
        $task->hydrate($row);

        return $task;
    }

    public function save(Task $task): int
    {
        return $task->save($this->connection);
    }

    public function owner(Task $task): ?User
    {
        $row = $this->belongsTo('users', $task->userId)->row();

        if (count($row) === 0) {
            return null;
        }

        $user = new User('');
        $user->hydrate($row);

        return $user;
    }
}

$application = new Application('com.cyonima.demo');

$db = new Connection(':memory:');
$migrator = new Migrator($db);
$migrator->add(new CreateSchema());
$migrator->run();

$users = new UserRepository($db);
$tasksRepo = new TaskRepository($db);

$alice = new User('Alice');
$users->save($alice);

$tasks = [];
$first = new Task($alice->id, 'Compiler la démonstration', true);
$tasksRepo->save($first);
$tasks[] = $first;

$second = new Task($alice->id, 'Valider les templates', false);
$tasksRepo->save($second);
$tasks[] = $second;

$objects = new ObjectList($tasks);
$view = ListView::fromObjects($objects, 0);

$draft = new Entry('Nouvelle tâche');
$add = new Button('Ajouter');
$status = new Label('Prêt');
$status->addClass('demo-status');

$validator = new Validator();
$validator->add('title', new Required());
$validator->add('title', new MinLength(3));

$addTask = function (string $title) use (
    $validator,
    $tasksRepo,
    $alice,
    &$tasks,
    $objects,
    $view,
    $status,
    $draft
): void {
    $title = trim($title);

    try {
        $validator->validateOrFail(['title' => $title]);
    } catch (ValidationException $error) {
        $status->setText('Erreur : ' . $error->getMessage());

        return;
    }

    $task = new Task($alice->id, $title, false);
    $tasksRepo->save($task);
    $tasks[] = $task;
    $objects->add($task);
    $view->model->append($task->listLabel());
    $status->setText('Ajoutée : ' . $title);
    $draft->setText('');
};

$draft->onActivate($addTask);
$add->onClicked(function () use ($draft, $addTask): void {
    $addTask($draft->text());
});

$view->onActivated(function (int $index) use (&$tasks, $tasksRepo, $status): void {
    if ($index < 0 || $index >= count($tasks)) {
        return;
    }

    $task = $tasks[$index];
    $owner = $tasksRepo->owner($task);

    if ($owner !== null) {
        $status->setText($task->title . ' — ' . $owner->name);
    } else {
        $status->setText($task->title);
    }
});

$onQuit = function () use ($application): void {
    $application->quit();
};

$onAbout = function () use ($status): void {
    $status->setText('Cyonima demo — templates, ORM et validation');
};

$window = DemoPage::build(
    'Cyonima Demo',
    $draft,
    $add,
    $status,
    $view,
    $onQuit,
    $onAbout
);
$window->present();

$arguments = cli_args();

if (count($arguments) > 1 && $arguments[1] === '--auto') {
    $application->after(150, function () use ($application): void {
        $application->quit();
    });
}

$application->run();
