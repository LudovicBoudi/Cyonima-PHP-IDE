<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class SwitchStmt extends Stmt
{
    /** @param list<SwitchCase> $cases */
    public function __construct(
        public readonly Expr $subject,
        public readonly array $cases,
        int $line,
    ) {
        parent::__construct($line);
    }
}
