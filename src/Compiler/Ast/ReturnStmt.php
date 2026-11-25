<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class ReturnStmt extends Stmt
{
    public function __construct(
        public readonly ?Expr $value,
        int $line,
    ) {
        parent::__construct($line);
    }
}
