<?php

declare(strict_types=1);

/**
 * Exemple de classes et objets compilés.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-classes.php --emit=elf
 *   ./dist/native-classes
 */

class Counter
{
    public int $value;

    public function __construct(int $start)
    {
        $this->value = $start;
    }

    public function add(int $amount): void
    {
        $this->value = $this->value + $amount;
    }

    public function get(): int
    {
        return $this->value;
    }
}

$counter = new Counter(5);
$counter->add(3);
echo $counter->get(), "\n";
