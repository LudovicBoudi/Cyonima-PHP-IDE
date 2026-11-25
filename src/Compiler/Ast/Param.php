<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class Param extends Node
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        int $line,
    ) {
        parent::__construct($line);
    }
}
