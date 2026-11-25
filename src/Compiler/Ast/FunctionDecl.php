<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class FunctionDecl extends Node
{
    /**
     * @param list<Param> $params
     */
    public function __construct(
        public readonly string $name,
        public readonly array $params,
        public readonly string $returnType,
        public readonly Block $body,
        int $line,
    ) {
        parent::__construct($line);
    }
}
