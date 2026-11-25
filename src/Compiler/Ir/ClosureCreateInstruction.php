<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class ClosureCreateInstruction extends Instruction
{
    /**
     * @param list<Operand> $captures
     */
    public function __construct(
        public readonly IrClosure $closure,
        public readonly array $captures,
        public readonly TemporaryOperand $result,
    ) {
    }
}
