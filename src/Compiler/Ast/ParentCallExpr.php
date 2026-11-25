<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class ParentCallExpr extends Expr
{
    public ?string $targetClass = null;

    /** @param list<Expr> $arguments */
    public function __construct(
        public readonly string $method,
        public readonly array $arguments,
        int $line,
    ) {
        parent::__construct($line);
    }
}
