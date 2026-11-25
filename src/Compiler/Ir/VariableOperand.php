<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class VariableOperand extends Operand
{
    public function __construct(
        public readonly string $name,
        string $type,
    ) {
        parent::__construct($type);
    }
}
