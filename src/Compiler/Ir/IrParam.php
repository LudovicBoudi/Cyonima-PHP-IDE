<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class IrParam
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
    ) {
    }
}
