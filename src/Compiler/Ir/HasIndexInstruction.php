<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class HasIndexInstruction extends Instruction
{
    public function __construct(
        public readonly Operand $collection,
        public readonly Operand $key,
        public readonly TemporaryOperand $result,
        public readonly bool $map,
    ) {
    }
}
