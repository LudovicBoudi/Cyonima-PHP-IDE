<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class InterfaceDecl extends Node
{
    /**
     * @param list<string> $parents
     * @param list<MethodDecl> $methods
     */
    public function __construct(
        public readonly string $name,
        public readonly array $parents,
        public readonly array $methods,
        int $line,
    ) {
        parent::__construct($line);
    }
}
