<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class BinaryInstruction extends Instruction
{
    public function __construct(
        public readonly string $operator,
        public readonly Operand $left,
        public readonly Operand $right,
        public readonly TemporaryOperand $result,
    ) {
    }
}
