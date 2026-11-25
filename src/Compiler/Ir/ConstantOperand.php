<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class ConstantOperand extends Operand
{
    public function __construct(
        string $type,
        public readonly mixed $value,
    ) {
        parent::__construct($type);
    }
}
