<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class ExpressionStmt extends Stmt
{
    public function __construct(
        public readonly Expr $expression,
        int $line,
    ) {
        parent::__construct($line);
    }
}
