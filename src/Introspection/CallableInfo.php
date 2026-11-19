<?php

declare(strict_types=1);

namespace Cyonima\Introspection;

final readonly class CallableInfo
{
    /**
     * @param list<Parameter> $parameters
     */
    public function __construct(
        public string $name,
        public ?string $symbol,
        public int $flags,
        public TypeRef $returnType,
        public array $parameters,
        public int $callerOwns,
        public bool $mayReturnNull,
        public bool $throws,
        public bool $deprecated = false,
    ) {
    }

    public function isConstructor(): bool
    {
        return ($this->flags & Gi::FUNCTION_IS_CONSTRUCTOR) !== 0;
    }

    public function isMethod(): bool
    {
        return ($this->flags & Gi::FUNCTION_IS_METHOD) !== 0;
    }

    public function isGetter(): bool
    {
        return ($this->flags & Gi::FUNCTION_IS_GETTER) !== 0;
    }

    public function isSetter(): bool
    {
        return ($this->flags & Gi::FUNCTION_IS_SETTER) !== 0;
    }

    /** Vrai si la signature ne contient que des entrées simples. */
    public function hasOnlyInputParameters(): bool
    {
        foreach ($this->parameters as $parameter) {
            if (!$parameter->isIn()) {
                return false;
            }
        }
        return true;
    }
}
