<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class WhileStmt extends Stmt
{
    public function __construct(
        public readonly Expr $condition,
        public readonly Stmt $body,
        int $line,
    ) {
        parent::__construct($line);
    }
}
