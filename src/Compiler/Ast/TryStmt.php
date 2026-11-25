<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class TryStmt extends Stmt
{
    public function __construct(
        public readonly Block $body,
        public readonly CatchClause $catch,
        int $line,
    ) {
        parent::__construct($line);
    }
}
