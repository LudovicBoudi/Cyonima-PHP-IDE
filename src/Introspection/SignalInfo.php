<?php

declare(strict_types=1);

namespace Cyonima\Introspection;

final readonly class SignalInfo
{
    /**
     * @param list<Parameter> $parameters paramètres hors instance
     */
    public function __construct(
        public string $name,
        public array $parameters,
        public TypeRef $returnType,
        public bool $deprecated = false,
    ) {
    }

    public function wantsBooleanReturn(): bool
    {
        return !$this->returnType->isVoid() && $this->returnType->isBoolean();
    }
}
