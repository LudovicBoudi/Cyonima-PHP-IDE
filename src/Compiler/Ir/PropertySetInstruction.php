<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class PropertySetInstruction extends Instruction
{
    public function __construct(
        public readonly Operand $object,
        public readonly string $property,
        public readonly Operand $value,
    ) {
    }
}
