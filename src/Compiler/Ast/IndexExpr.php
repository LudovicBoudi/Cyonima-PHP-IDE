<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class IndexExpr extends Expr
{
    public function __construct(
        public readonly VariableExpr $array,
        public readonly ?Expr $index,
        int $line,
    ) {
        parent::__construct($line);
    }
}
