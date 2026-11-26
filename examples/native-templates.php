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

$onToggle = function (): void {
    echo "Bascule demandée\n";
};

$application = new Application('com.cyonima.templates.demo');
$application->loadCss(
    '.task-window { background: #101418; color: #e6edf3; }'
    . '.task-panel { padding: 16px; }'
    . '.title { font-size: 22px; font-weight: bold; }'
    . '.hint { color: #8b949e; font-size: 12px; }'
    . '.status { color: #3ddc97; }'
    . '.primary { background: #238636; color: white; }'
);

$window = NativeTodoList::build($todos, 'Tâches', 0.66, $onAdd, $onToggle);
$window->present();
$application->run();
