<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class AssignExpr extends Expr
{
    public function __construct(
        public readonly string $name,
        public readonly Expr $value,
        int $line,
    ) {
        parent::__construct($line);
    }
}
