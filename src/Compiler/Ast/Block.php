<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class Block extends Stmt
{
    /** @param list<Stmt> $statements */
    public function __construct(
        public readonly array $statements,
        int $line,
    ) {
        parent::__construct($line);
    }
}
