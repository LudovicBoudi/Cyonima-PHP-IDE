<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class IndexAssignExpr extends Expr
{
    public function __construct(
        public readonly VariableExpr $array,
        public readonly Expr $index,
        public readonly Expr $value,
        int $line,
    ) {
        parent::__construct($line);
    }
}
