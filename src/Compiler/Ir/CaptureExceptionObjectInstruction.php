<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class CaptureExceptionObjectInstruction extends Instruction
{
    public function __construct(
        public readonly TemporaryOperand $result,
    ) {
    }
}
