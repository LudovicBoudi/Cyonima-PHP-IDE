<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Semantic;

use Cyonima\Compiler\Ast\MethodDecl;

final class ClassInfo
{
    /**
     * @param array<string, string> $properties
     * @param array<string, string> $staticProperties
     * @param array<string, string> $staticPropertyOwners
     * @param array<string, FunctionInfo> $methods
     * @param array<string, FunctionInfo> $declaredMethods
     * @param array<string, MethodDecl> $methodDeclarations
     * @param list<string> $interfaces
     * @param array<string, string> $methodOwners
     * @param list<array{0: ?string, 1: string}> $vtable
     * @param array<string, int> $vtableSlots
     * @param array<string, array{id: int, vtable: list<array{0: ?string, 1: string}>}> $interfaceVtables
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $parentName,
        public readonly bool $isInterface,
        public readonly bool $isAbstract,
        public readonly array $interfaces,
        public readonly array $properties,
        public readonly array $staticProperties,
        public readonly array $staticPropertyOwners,
        public readonly array $methods,
        public readonly array $declaredMethods,
        public readonly array $methodDeclarations,
        public readonly array $methodOwners,
        public readonly ?string $constructor,
        public readonly ?string $constructorClass,
        public readonly array $vtable,
        public readonly array $vtableSlots,
        public readonly array $interfaceVtables,
        public readonly ?int $interfaceId,
    ) {
    }
}
