<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

abstract class Operand
{
    public function __construct(public readonly string $type)
    {
    }
}
