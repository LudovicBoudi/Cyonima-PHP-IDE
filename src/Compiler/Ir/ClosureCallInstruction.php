<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class ClosureCallInstruction extends Instruction
{
    /**
     * @param list<Operand> $arguments
     * @param list<string> $parameterTypes
     */
    public function __construct(
        public readonly Operand $closure,
        public readonly array $arguments,
        public readonly array $parameterTypes,
        public readonly string $returnType,
        public readonly ?TemporaryOperand $result,
    ) {
    }
}
