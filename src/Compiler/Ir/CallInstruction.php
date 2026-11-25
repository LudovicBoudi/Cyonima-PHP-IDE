<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class CallInstruction extends Instruction
{
    /**
     * @param list<Operand> $arguments
     * @param null|list<string> $parameterTypes
     */
    public function __construct(
        public readonly string $name,
        public readonly array $arguments,
        public readonly ?TemporaryOperand $result,
        public readonly ?array $parameterTypes = null,
    ) {
    }
}
