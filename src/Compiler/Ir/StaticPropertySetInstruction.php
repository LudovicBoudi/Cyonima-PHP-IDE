<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class StaticPropertySetInstruction extends Instruction
{
    public function __construct(
        public readonly string $className,
        public readonly string $property,
        public readonly string $propertyType,
        public readonly Operand $value,
    ) {
    }
}
