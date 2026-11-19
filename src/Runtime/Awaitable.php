<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

/** Quelque chose qui se termine plus tard et dont on peut attendre le résultat. */
interface Awaitable
{
    public function isComplete(): bool;

    /** @param callable(self): void $callback */
    public function onComplete(callable $callback): void;

    /** @throws \RuntimeException si non terminé */
    public function result(): mixed;
}
