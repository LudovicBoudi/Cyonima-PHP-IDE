<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class MapIndexInstruction extends Instruction
{
    public function __construct(
        public readonly Operand $map,
        public readonly Operand $key,
        public readonly TemporaryOperand $result,
        public readonly string $valueType,
    ) {
    }
}
