<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class CatchClause extends Node
{
    public function __construct(
        public readonly string $type,
        public readonly string $variable,
        public readonly Block $body,
        int $line,
    ) {
        parent::__construct($line);
    }
}
