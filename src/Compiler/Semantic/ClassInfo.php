<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Semantic;

use Cyonima\Compiler\Ast\MethodDecl;

final class ClassInfo
{
    /**
     * @param array<string, string> $properties
     * @param array<string, FunctionInfo> $methods
     * @param array<string, MethodDecl> $methodDeclarations
     */
    public function __construct(
        public readonly string $name,
        public readonly array $properties,
        public readonly array $methods,
        public readonly array $methodDeclarations,
        public readonly ?string $constructor,
    ) {
    }
}
