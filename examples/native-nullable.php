<?php

declare(strict_types=1);

/**
 * Exemple de types nullables.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-nullable.php --emit=elf
 *   ./dist/native-nullable
 */

class User
{
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function label(): string
    {
        return $this->name;
    }
}

function find(bool $present): ?User
{
    if (!$present) {
        return null;
    }

    return new User('alice');
}

function greet(?string $name): string
{
    if ($name === null) {
        return 'nobody';
    }

    return 'hello ' . $name;
}

$found = find(true);
$missing = find(false);

if ($found !== null) {
    echo $found->label(), "\n";
}

if ($missing === null) {
    echo "missing\n";
}

echo greet('bob'), ' ', greet(null), "\n";

try {
    echo $missing->label(), "\n";
} catch (string $error) {
    echo 'catch: ', $error, "\n";
}
