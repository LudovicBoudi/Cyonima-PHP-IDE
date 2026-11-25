<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class EchoStmt extends Stmt
{
    /** @param list<Expr> $expressions */
    public function __construct(
        public readonly array $expressions,
        int $line,
    ) {
        parent::__construct($line);
    }
}
