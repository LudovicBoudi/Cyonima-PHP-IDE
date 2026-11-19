<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

use LogicException;

/**
 * Ordonnanceur coopératif basé sur les Fibers.
 *
 * PHP est mono-thread : une tâche ne peut être interrompue que si elle rend la
 * main (`Async::sleep()`, `Async::yield()` ou `Async::await()`). Une boucle CPU
 * qui n'appelle jamais l'un des trois fige l'UI : dans ce cas, utiliser un
 * sous-processus (`Process`).
 */
final class Scheduler
{
    /** @var list<Task> */
    private static array $ready = [];

    /** @var list<array{at: float, task: Task}> */
    private static array $sleepers = [];

    private static int $nextId = 0;

    public static function spawn(callable $task, mixed ...$arguments): Task
    {
        $handle = new Task(++self::$nextId);

        $fiber = new \Fiber(static function () use ($task, $arguments, $handle): void {
            try {
                $handle->state = Task::RUNNING;
                $handle->result = $task(...$arguments);
                $handle->state = Task::DONE;
            } catch (\Throwable $exception) {
                $handle->error = $exception;
                $handle->state = Task::FAILED;
                Errors::report($exception, 'task');
            } finally {
                $handle->settle();
            }
        });

        $handle->fiber = $fiber;
        self::step($handle);

        return $handle;
    }

    /**
     * Déroule les tâches prêtes pendant au plus $budgetMs.
     *
     * @return int nombre de tâches réveillées
     */
    public static function tick(float $budgetMs = 6.0): int
    {
        $start = Loop::now();

        if (self::$sleepers !== []) {
            $remaining = [];
            foreach (self::$sleepers as $sleeper) {
                if ($sleeper['at'] <= $start) {
                    self::$ready[] = $sleeper['task'];
                    continue;
                }
                $remaining[] = $sleeper;
            }
            self::$sleepers = $remaining;
        }

        $processed = 0;
        while (self::$ready !== [] && (Loop::now() - $start) < $budgetMs) {
            $task = array_shift(self::$ready);
            if (!$task instanceof Task || $task->isSettled()) {
                continue;
            }
            self::step($task);
            $processed++;
        }

        return $processed;
    }

    public static function sleep(int $milliseconds): void
    {
        if (\Fiber::getCurrent() === null) {
            throw new LogicException('Async::sleep() doit être appelé depuis une tâche Async::run().');
        }
        \Fiber::suspend(['sleep' => max(0, $milliseconds)]);
    }

    public static function yieldNow(): void
    {
        if (\Fiber::getCurrent() === null) {
            throw new LogicException('Async::yield() doit être appelé depuis une tâche Async::run().');
        }
        \Fiber::suspend(null);
    }

    public static function await(Awaitable $awaitable): mixed
    {
        if (\Fiber::getCurrent() === null) {
            throw new LogicException('Async::await() doit être appelé depuis une tâche Async::run().');
        }

        while (!$awaitable->isComplete()) {
            \Fiber::suspend($awaitable);
        }

        return $awaitable->result();
    }

    /** Nombre de tâches vivantes (prêtes ou endormies). */
    public static function pending(): int
    {
        return count(self::$ready) + count(self::$sleepers);
    }

    public static function reset(): void
    {
        self::$ready = [];
        self::$sleepers = [];
    }

    private static function step(Task $task): void
    {
        if ($task->isSettled() || $task->fiber === null) {
            return;
        }

        $fiber = $task->fiber;

        try {
            $value = $fiber->isStarted() ? $fiber->resume() : $fiber->start();
        } catch (\Throwable $exception) {
            if (!$task->isSettled()) {
                $task->error = $exception;
                $task->state = Task::FAILED;
                $task->settle();
            }
            Errors::report($exception, 'task');
            return;
        }

        if ($fiber->isTerminated()) {
            if (!$task->isSettled()) {
                $task->state = Task::DONE;
                $task->settle();
            }
            return;
        }

        $task->state = Task::SUSPENDED;

        if ($value instanceof Awaitable) {
            if ($value->isComplete()) {
                self::$ready[] = $task;
                return;
            }
            $value->onComplete(static function () use ($task): void {
                self::$ready[] = $task;
            });
            return;
        }

        if (is_array($value) && array_key_exists('sleep', $value)) {
            self::$sleepers[] = [
                'at' => Loop::now() + max(0, (int) $value['sleep']),
                'task' => $task,
            ];
            return;
        }

        self::$ready[] = $task;
    }
}
