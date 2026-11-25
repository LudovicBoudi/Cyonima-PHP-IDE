<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class StaticCallExpr extends Expr
{
    public ?string $resolvedClass = null;

    /** @param list<Expr> $arguments */
    public function __construct(
        public readonly string $className,
        public readonly string $method,
        public readonly array $arguments,
        int $line,
    ) {
        parent::__construct($line);
    }
}
