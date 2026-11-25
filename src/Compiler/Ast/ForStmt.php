<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class ForStmt extends Stmt
{
    public function __construct(
        public readonly ?Expr $init,
        public readonly ?Expr $condition,
        public readonly ?Expr $step,
        public readonly Stmt $body,
        int $line,
    ) {
        parent::__construct($line);
    }
}
