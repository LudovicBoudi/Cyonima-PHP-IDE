<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class IndexInstruction extends Instruction
{
    public function __construct(
        public readonly Operand $array,
        public readonly Operand $index,
        public readonly TemporaryOperand $result,
        public readonly string $elementType,
    ) {
    }
}
