<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class AppendInstruction extends Instruction
{
    public function __construct(
        public readonly Operand $array,
        public readonly Operand $value,
        public readonly string $elementType,
    ) {
    }
}
