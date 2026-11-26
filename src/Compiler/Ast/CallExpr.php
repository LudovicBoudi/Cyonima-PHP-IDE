<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class CallExpr extends Expr
{
    public ?string $resolvedName = null;

    /** @param list<Expr> $arguments */
    public function __construct(
        public readonly string $name,
        public readonly array $arguments,
        int $line,
        public readonly ?string $namespace = null,
    ) {
        parent::__construct($line);
    }
}
