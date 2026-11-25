<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class ArrayItem extends Node
{
    public function __construct(
        public readonly ?Expr $key,
        public readonly Expr $value,
        int $line,
    ) {
        parent::__construct($line);
    }
}
