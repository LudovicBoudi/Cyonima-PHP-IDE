<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class PropertyDecl extends Node
{
    public function __construct(
        public readonly string $type,
        public readonly string $name,
        int $line,
    ) {
        parent::__construct($line);
    }
}
