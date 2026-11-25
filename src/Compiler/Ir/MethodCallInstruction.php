<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class MethodCallInstruction extends Instruction
{
    /**
     * @param list<Operand> $arguments
     */
    public function __construct(
        public readonly string $className,
        public readonly string $method,
        public readonly Operand $object,
        public readonly array $arguments,
        public readonly ?TemporaryOperand $result,
    ) {
    }
}
