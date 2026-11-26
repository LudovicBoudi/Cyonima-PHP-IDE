<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class ThrowObjectInstruction extends Instruction
{
    public function __construct(
        public readonly Operand $object,
        public readonly Operand $message,
    ) {
    }
}
