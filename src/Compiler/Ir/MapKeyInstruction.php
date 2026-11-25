<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class MapKeyInstruction extends Instruction
{
    public function __construct(
        public readonly Operand $map,
        public readonly Operand $index,
        public readonly TemporaryOperand $result,
    ) {
    }
}
