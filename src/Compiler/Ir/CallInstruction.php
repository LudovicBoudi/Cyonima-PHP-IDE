<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class CallInstruction extends Instruction
{
    /**
     * @param list<Operand> $arguments
     */
    public function __construct(
        public readonly string $name,
        public readonly array $arguments,
        public readonly ?TemporaryOperand $result,
    ) {
    }
}
