<?php

declare(strict_types=1);

namespace Cyonima\Introspection;

final readonly class Parameter
{
    public function __construct(
        public string $name,
        public TypeRef $type,
        public int $direction,
        public int $transfer,
        public bool $nullable,
        public bool $optional,
        public bool $callerAllocates,
    ) {
    }

    public function isIn(): bool
    {
        return $this->direction === Gi::DIRECTION_IN;
    }

    public function isOut(): bool
    {
        return $this->direction === Gi::DIRECTION_OUT;
    }
}
