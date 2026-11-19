<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

use LogicException;
use Throwable;

/** Tâche coopérative exécutée dans une Fiber. */
final class Task implements Awaitable
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const SUSPENDED = 'suspended';
    public const DONE = 'done';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    public string $state = self::PENDING;
    public mixed $result = null;
    public ?Throwable $error = null;
    public ?\Fiber $fiber = null;

    /** @var list<callable> */
    private array $completions = [];

    public function __construct(public readonly int $id)
    {
    }

    public function isSettled(): bool
    {
        return $this->state === self::DONE
            || $this->state === self::FAILED
            || $this->state === self::CANCELLED;
    }

    public function isComplete(): bool
    {
        return $this->isSettled();
    }

    public function isDone(): bool
    {
        return $this->state === self::DONE;
    }

    public function onComplete(callable $callback): void
    {
        if ($this->isSettled()) {
            $callback($this);
            return;
        }
        $this->completions[] = $callback;
    }

    public function then(callable $onFulfilled): self
    {
        $this->onComplete(static function (self $task) use ($onFulfilled): void {
            if ($task->state === self::DONE) {
                $onFulfilled($task->result, $task);
            }
        });
        return $this;
    }

    public function onError(callable $onFailed): self
    {
        $this->onComplete(static function (self $task) use ($onFailed): void {
            if ($task->state === self::FAILED && $task->error !== null) {
                $onFailed($task->error, $task);
            }
        });
        return $this;
    }

    public function result(): mixed
    {
        if ($this->state === self::FAILED && $this->error !== null) {
            throw $this->error;
        }
        if (!$this->isSettled()) {
            throw new LogicException(sprintf('La tâche #%d n\'est pas terminée (%s)', $this->id, $this->state));
        }
        return $this->result;
    }

    /** @internal appelé par le Scheduler */
    public function settle(): void
    {
        $completions = $this->completions;
        $this->completions = [];

        foreach ($completions as $completion) {
            try {
                $completion($this);
            } catch (Throwable $exception) {
                Errors::report($exception, 'task completion');
            }
        }
    }
}
