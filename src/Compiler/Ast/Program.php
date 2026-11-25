<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class Program extends Node
{
    /**
     * @param list<FunctionDecl> $functions
     * @param list<ClassDecl> $classes
     * @param list<Stmt> $statements
     */
    public function __construct(
        public readonly array $functions,
        public readonly array $classes,
        public readonly array $statements,
        int $line = 1,
    ) {
        parent::__construct($line);
    }
}
