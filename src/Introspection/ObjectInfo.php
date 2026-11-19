<?php

declare(strict_types=1);

namespace Cyonima\Introspection;

final readonly class ObjectInfo
{
    /**
     * @param list<CallableInfo> $methods
     * @param list<SignalInfo> $signals
     */
    public function __construct(
        public string $namespace,
        public string $name,
        public int $kind,
        public ?string $parentFqcn,
        public array $methods,
        public array $signals,
        public bool $deprecated = false,
        public ?string $typeName = null,
    ) {
    }

    public function isInterface(): bool
    {
        return $this->kind === Gi::INFO_INTERFACE;
    }

    public function fqcn(): string
    {
        return $this->namespace . '\\' . $this->name;
    }
}
