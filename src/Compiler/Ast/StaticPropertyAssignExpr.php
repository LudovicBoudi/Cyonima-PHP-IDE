<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class StaticPropertyAssignExpr extends Expr
{
    public ?string $ownerClass = null;

    public function __construct(
        public readonly string $className,
        public readonly string $property,
        public readonly Expr $value,
        int $line,
    ) {
        parent::__construct($line);
    }
}
