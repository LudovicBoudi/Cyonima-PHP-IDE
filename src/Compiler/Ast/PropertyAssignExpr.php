<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class PropertyAssignExpr extends Expr
{
    public function __construct(
        public readonly Expr $object,
        public readonly string $property,
        public readonly Expr $value,
        int $line,
    ) {
        parent::__construct($line);
    }
}
