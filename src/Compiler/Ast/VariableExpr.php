<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class VariableExpr extends Expr
{
    public function __construct(
        public readonly string $name,
        int $line,
    ) {
        parent::__construct($line);
    }
}
