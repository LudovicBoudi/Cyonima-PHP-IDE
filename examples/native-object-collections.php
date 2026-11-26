<?php

declare(strict_types=1);

/**
 * Exemple de collections d’objets.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-object-collections.php --emit=elf
 *   ./dist/native-object-collections
 */

interface Named
{
    public function name(): string;
}

class User implements Named
{
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function name(): string
    {
        return $this->name;
    }
}

/**
 * @return array<User>
 */
function makeUsers(): array
{
    return [new User('alice'), new User('bob')];
}

/**
 * @param array<Named> $items
 */
function joinNames(array $items): string
{
    $result = '';

    foreach ($items as $item) {
        $result = $result . $item->name() . ',';
    }

    return $result;
}

/**
 * @param array<User> $users
 * @return array<string,User>
 */
function indexByName(array $users): array
{
    $table = [];

    foreach ($users as $user) {
        $table[$user->name] = $user;
    }

    return $table;
}

$users = makeUsers();
$users[] = new User('carol');

echo count($users), ' ', joinNames($users), "\n";

$table = indexByName($users);
echo count($table), ' ', $table['bob']->name(), "\n";
