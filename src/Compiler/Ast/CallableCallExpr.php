<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class CallableCallExpr extends Expr
{
    /** @param list<Expr> $arguments */
    public function __construct(
        public readonly VariableExpr $callee,
        public readonly array $arguments,
        int $line,
    ) {
        parent::__construct($line);
    }
}
