<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class IrMethod
{
    public function __construct(
        public readonly string $class,
        public readonly string $name,
        public readonly IrFunction $function,
    ) {
    }
}
