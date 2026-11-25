<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class MapInstruction extends Instruction
{
    /**
     * @param list<array{0: Operand, 1: Operand}> $entries
     */
    public function __construct(
        public readonly string $valueType,
        public readonly array $entries,
        public readonly TemporaryOperand $result,
    ) {
    }
}
