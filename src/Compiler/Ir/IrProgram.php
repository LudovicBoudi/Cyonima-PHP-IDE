<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class IrProgram
{
    /**
     * @param list<IrFunction> $functions
     */
    public function __construct(
        public readonly array $functions,
        public readonly IrFunction $main,
    ) {
    }
}
