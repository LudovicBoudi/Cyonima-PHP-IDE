<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class ClassDecl extends Node
{
    /**
     * @param list<PropertyDecl> $properties
     * @param list<MethodDecl> $methods
     */
    public function __construct(
        public readonly string $name,
        public readonly array $properties,
        public readonly array $methods,
        int $line,
    ) {
        parent::__construct($line);
    }
}
