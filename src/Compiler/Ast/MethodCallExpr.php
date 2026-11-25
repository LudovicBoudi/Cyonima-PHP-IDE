<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class MethodCallExpr extends Expr
{
    /** @param list<Expr> $arguments */
    public function __construct(
        public readonly Expr $object,
        public readonly string $method,
        public readonly array $arguments,
        int $line,
    ) {
        parent::__construct($line);
    }
}
