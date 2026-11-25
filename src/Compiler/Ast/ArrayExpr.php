<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class ArrayExpr extends Expr
{
    /** @param list<Expr> $elements */
    public function __construct(
        public readonly array $elements,
        int $line,
    ) {
        parent::__construct($line);
    }
}
