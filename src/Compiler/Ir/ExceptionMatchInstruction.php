<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ir;

final class ExceptionMatchInstruction extends Instruction
{
    /**
     * @param null|string $classType null pour une capture `string`
     */
    public function __construct(
        public readonly ?string $classType,
        public readonly TemporaryOperand $result,
    ) {
    }
}
