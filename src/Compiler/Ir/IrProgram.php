<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class IrProgram
{
    /**
     * @param list<IrFunction> $functions
     * @param list<IrMethod> $methods
     * @param list<IrClosure> $closures
     */
    public function __construct(
        public readonly array $functions,
        public readonly array $methods,
        public readonly array $closures,
        public readonly IrFunction $main,
    ) {
    }
}
