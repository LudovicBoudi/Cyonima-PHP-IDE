<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class IrFunction
{
    /**
     * @param list<IrParam> $params
     * @param array<string, string> $variables
     * @param array<int, string> $temporaries
     * @param list<Instruction> $instructions
     */
    public function __construct(
        public readonly string $name,
        public readonly array $params,
        public readonly string $returnType,
        public readonly array $variables,
        public array $temporaries = [],
        public array $instructions = [],
        /** @var array<string, true> */
        public array $boxedVariables = [],
    ) {
    }
}
