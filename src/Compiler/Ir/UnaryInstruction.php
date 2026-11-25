<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class UnaryInstruction extends Instruction
{
    public function __construct(
        public readonly string $operator,
        public readonly Operand $operand,
        public readonly TemporaryOperand $result,
    ) {
    }
}
