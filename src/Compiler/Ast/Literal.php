<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class Literal extends Expr
{
    public function __construct(
        public readonly mixed $value,
        public readonly string $literalType,
        int $line,
    ) {
        parent::__construct($line);
        $this->type = $literalType;
    }
}
