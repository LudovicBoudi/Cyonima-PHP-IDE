<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class PropertyAccessExpr extends Expr
{
    public function __construct(
        public readonly Expr $object,
        public readonly string $property,
        int $line,
    ) {
        parent::__construct($line);
    }
}
