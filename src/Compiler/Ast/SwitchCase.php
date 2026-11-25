<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class SwitchCase extends Node
{
    /** @param list<Stmt> $statements */
    public function __construct(
        public readonly ?Expr $condition,
        public readonly array $statements,
        int $line,
    ) {
        parent::__construct($line);
    }
}
