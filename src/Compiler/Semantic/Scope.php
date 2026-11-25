<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Semantic;

final class Scope
{
    /** @var array<string, string> */
    public array $variables = [];

    /** @param array<string, string> $initial */
    public function __construct(array $initial = [])
    {
        $this->variables = $initial;
    }

    public function has(string $name): bool
    {
        return isset($this->variables[$name]);
    }

    public function typeOf(string $name): ?string
    {
        return $this->variables[$name] ?? null;
    }

    public function define(string $name, string $type): void
    {
        $this->variables[$name] = $type;
    }
}
