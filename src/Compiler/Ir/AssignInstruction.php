<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class AssignInstruction extends Instruction
{
    public function __construct(
        public readonly Operand $target,
        public readonly Operand $value,
    ) {
    }
}
