<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class EchoInstruction extends Instruction
{
    public function __construct(public readonly Operand $value)
    {
    }
}
