<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class ForeachStmt extends Stmt
{
    public ?string $valueType = null;

    public function __construct(
        public readonly Expr $iterable,
        public readonly ?string $keyName,
        public readonly string $valueName,
        public readonly Stmt $body,
        int $line,
    ) {
        parent::__construct($line);
    }
}
