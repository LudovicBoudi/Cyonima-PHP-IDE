<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class ArrayInstruction extends Instruction
{
    /**
     * @param list<Operand> $elements
     */
    public function __construct(
        public readonly ?string $elementType,
        public readonly array $elements,
        public readonly TemporaryOperand $result,
    ) {
    }
}
