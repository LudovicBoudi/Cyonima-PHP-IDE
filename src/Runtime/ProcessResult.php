<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

final readonly class ProcessResult
{
    /** @param list<string> $command */
    public function __construct(
        public array $command,
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public float $durationMs,
    ) {
    }

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }

    public function output(): string
    {
        return trim($this->stdout);
    }
}
