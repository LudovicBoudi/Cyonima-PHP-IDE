<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class JumpIfFalseInstruction extends Instruction
{
    public function __construct(
        public readonly Operand $condition,
        public readonly string $label,
    ) {
    }
}
