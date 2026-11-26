<?php

declare(strict_types=1);

/**
 * Exemple de template `.ui` compilé vers GTK.
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

    public function __construct(string $title, string $status)
    {
        $this->title = $title;
        $this->status = $status;
    }
}

$todos = [];
$todos[] = new Todo('Compiler le runtime', 'fait');
$todos[] = new Todo('Compiler les templates', 'en cours');
$todos[] = new Todo('Empaqueter l’application', 'à faire');

$onAdd = function (): void {
    echo "Ajout demandé\n";
};

$application = new Application('com.cyonima.templates.demo');
$window = NativeTodoList::build($todos, 'Tâches', $onAdd);
$window->present();
$application->run();
