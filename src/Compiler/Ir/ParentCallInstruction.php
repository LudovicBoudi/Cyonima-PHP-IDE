<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class ParentCallInstruction extends Instruction
{
    /**
     * @param list<Operand> $arguments
     * @param list<string> $parameterTypes
     */
    public function __construct(
        public readonly string $className,
        public readonly string $method,
        public readonly Operand $object,
        public readonly array $arguments,
        public readonly array $parameterTypes,
        public readonly string $returnType,
        public readonly ?TemporaryOperand $result,
    ) {
    }
}
