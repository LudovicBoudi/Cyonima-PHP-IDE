<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class BinaryExpr extends Expr
{
    public function __construct(
        public readonly string $operator,
        public readonly Expr $left,
        public readonly Expr $right,
        int $line,
    ) {
        parent::__construct($line);
    }
}
