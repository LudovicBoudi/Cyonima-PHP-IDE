<?php

declare(strict_types=1);

/**
 * Exemple de membres statiques compilés.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-static.php --emit=elf
 *   ./dist/native-static
 */

class Registry
{
    public static int $created;
    public static string $last;

    public static function register(string $name): void
    {
        self::$created = self::$created + 1;
        self::$last = $name;
    }

    public static function summary(): string
    {
        return strval(self::$created) . ':' . self::$last;
    }
}

Registry::register('alpha');
Registry::register('beta');
echo Registry::summary(), "\n";

Registry::$created = 10;
echo Registry::$created, "\n";
