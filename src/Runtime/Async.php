<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

/**
 * API applicative de l'asynchrone coopératif.
 *
 *   Async::run(function (): void {
 *       $this->label->text('chargement…');
 *       Async::sleep(500);          // l'UI reste réactive
 *       $this->label->text('fini');
 *   });
 *
 * Une tâche s'exécute immédiatement jusqu'à son premier point de suspension.
 */
final class Async
{
    public static function run(callable $task, mixed ...$arguments): Task
    {
        return Scheduler::spawn($task, ...$arguments);
    }

    public static function sleep(int $milliseconds): void
    {
        Scheduler::sleep($milliseconds);
    }

    public static function yield(): void
    {
        Scheduler::yieldNow();
    }

    public static function await(Task|Process $awaitable): mixed
    {
        return Scheduler::await($awaitable);
    }

    /** Nombre de tâches en cours (hors processus). */
    public static function pending(): int
    {
        return Scheduler::pending();
    }
}
