<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class ExceptionCheckInstruction extends Instruction
{
    public function __construct(public readonly string $label)
    {
    }
}
