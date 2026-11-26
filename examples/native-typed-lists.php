<?php

declare(strict_types=1);

/**
 * Exemple de modèle métier typé au-dessus de DropDown et ListBox.
 *
 *   bin/cyonima-compile --project=. \
 *       --php=examples/native-typed-lists.php \
 *       --templates=examples/native-typed-lists.ui \
 *       --emit=elf
 *   ./dist/native-typed-lists
 */

namespace App;

use Application;
use Cyonima\Templates\NativeTypedLists;
use Listable;
use ObjectList;

final class Todo implements Listable
{
    public string $title;
    public bool $done;

    public function __construct(string $title, bool $done)
    {
        $this->title = $title;
        $this->done = $done;
    }

    public function listLabel(): string
    {
        if ($this->done) {
            return '[x] ' . $this->title;
        }

        return '[ ] ' . $this->title;
    }
}

$application = new Application('com.cyonima.typed.lists.demo');

$todos = [];
$todos[] = new Todo('Compiler le modèle typé', true);
$todos[] = new Todo('Brancher DropDown', false);
$todos[] = new Todo('Brancher ListBox', false);

$objects = new ObjectList($todos);

$onSelect = function (int $index) use ($objects): void {
    $item = $objects->item($index);

    if ($item !== null) {
        echo 'sélection : ', $item->listLabel(), "\n";
    }
};

$window = NativeTypedLists::build($objects, 0, $onSelect);
$window->present();
$application->run();
