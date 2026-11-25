<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class IrClosure
{
    /**
     * @param list<IrParam> $params
     * @param array<string, string> $captures
     */
    public function __construct(
        public readonly int $id,
        public readonly array $params,
        public readonly array $captures,
        public readonly string $returnType,
        public readonly IrFunction $function,
    ) {
    }
}
