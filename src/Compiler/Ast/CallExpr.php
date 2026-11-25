<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class CallExpr extends Expr
{
    /** @param list<Expr> $arguments */
    public function __construct(
        public readonly string $name,
        public readonly array $arguments,
        int $line,
    ) {
        parent::__construct($line);
    }
}
