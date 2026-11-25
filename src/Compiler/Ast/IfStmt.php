<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class IfStmt extends Stmt
{
    public ?Stmt $else = null;

    public function __construct(
        public readonly Expr $condition,
        public readonly Stmt $then,
        int $line,
    ) {
        parent::__construct($line);
    }
}
