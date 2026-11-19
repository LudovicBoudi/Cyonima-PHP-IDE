<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

use Throwable;

/**
 * Point de report unique des erreurs applicatives.
 *
 * Règle R2 : une exception ne traverse jamais une frontière C. Les callbacks
 * GTK, les ticks de la boucle, les timers et les tâches asynchrones convergent
 * tous ici.
 */
final class Errors
{
    private static ?\Closure $handler = null;

    /** @var list<array{error: Throwable, context: string}> */
    private static array $history = [];

    private const HISTORY_LIMIT = 100;

    /** Installe un handler applicatif (log, dialogue, télémétrie…). */
    public static function handler(?callable $handler): void
    {
        self::$handler = $handler === null ? null : $handler(...);
    }

    public static function report(Throwable $error, string $context = ''): void
    {
        self::$history[] = ['error' => $error, 'context' => $context];
        if (count(self::$history) > self::HISTORY_LIMIT) {
            array_shift(self::$history);
        }

        if (self::$handler !== null) {
            (self::$handler)($error, $context);
            return;
        }

        fwrite(STDERR, sprintf(
            "[cyonima] %s%s: %s\n",
            $context === '' ? '' : "({$context}) ",
            $error::class,
            $error->getMessage(),
        ));

        if (getenv('CYONIMA_DEBUG') === '1') {
            fwrite(STDERR, $error->getTraceAsString() . "\n");
        }
    }

    /** @return list<array{error: Throwable, context: string}> */
    public static function history(): array
    {
        return self::$history;
    }

    public static function last(): ?Throwable
    {
        $last = end(self::$history);
        return $last === false ? null : $last['error'];
    }

    public static function reset(): void
    {
        self::$history = [];
    }
}
