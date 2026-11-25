<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class TemporaryOperand extends Operand
{
    public function __construct(
        public readonly int $id,
        string $type,
    ) {
        parent::__construct($type);
    }
}
