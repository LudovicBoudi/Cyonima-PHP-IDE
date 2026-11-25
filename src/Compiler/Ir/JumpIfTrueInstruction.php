<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class JumpIfTrueInstruction extends Instruction
{
    public function __construct(
        public readonly Operand $condition,
        public readonly string $label,
    ) {
    }
}
