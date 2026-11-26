<?php

declare(strict_types=1);

/**
 * Exemple de template `.ui` enrichi, compilé vers GTK.
 *
 *   bin/cyonima-compile --project=. \
 *       --php=examples/native-templates.php \
 *       --templates=examples/native-todo-list.ui \
 *       --emit=elf
 *   ./dist/native-templates
 */

namespace App;

use Application;
use Cyonima\Templates\NativeTodoList;

final class Todo
{
    public string $title;
    public string $status;
    public bool $done;

    public function __construct(string $title, string $status, bool $done)
    {
        $this->title = $title;
        $this->status = $status;
        $this->done = $done;
    }
}

$todos = [];
$todos[] = new Todo('Compiler le runtime', 'fait', true);
$todos[] = new Todo('Compiler les templates', 'en cours', false);
$todos[] = new Todo('Empaqueter l’application', 'à faire', false);

$onAdd = function (): void {
    echo "Ajout demandé\n";
};

$onToggle = function (bool $active): void {
    if ($active) {
        echo "Tâche cochée\n";
    } else {
        echo "Tâche décochée\n";
    }
};

$application = new Application('com.cyonima.templates.demo');
$window = NativeTodoList::build($todos, 'Tâches', 0.66, $onAdd, $onToggle);
$window->present();
$application->run();
