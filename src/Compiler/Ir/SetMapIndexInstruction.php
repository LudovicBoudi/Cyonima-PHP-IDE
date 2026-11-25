<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class SetMapIndexInstruction extends Instruction
{
    public function __construct(
        public readonly Operand $map,
        public readonly Operand $key,
        public readonly Operand $value,
        public readonly string $valueType,
    ) {
    }
}
