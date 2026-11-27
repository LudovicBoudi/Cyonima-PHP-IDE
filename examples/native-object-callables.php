<?php

declare(strict_types=1);

/**
 * Exemple de callables typés avec objets.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-object-callables.php --emit=elf
 *   ./dist/native-object-callables
 */

final class Todo
{
    public string $title;
    public bool $done;

    public function __construct(string $title, bool $done)
    {
        $this->title = $title;
        $this->done = $done;
    }
}

$format = function (Todo $todo): string {
    if ($todo->done) {
        return '[x] ' . $todo->title;
    }

    return '[ ] ' . $todo->title;
};

$make = function (string $title): Todo {
    return new Todo($title, false);
};

$label = function (?Todo $todo): string {
    if ($todo === null) {
        return 'aucune tâche';
    }

    return $todo->title;
};

$first = $make('Compiler les callables');
$second = new Todo('Documenter les objets', true);

echo $format($first), "\n";
echo $format($second), "\n";
echo $label(null), "\n";
echo $label($second), "\n";
