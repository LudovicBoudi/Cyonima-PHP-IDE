<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class TryStmt extends Stmt
{
    /**
     * @param list<CatchClause> $catches
     */
    public function __construct(
        public readonly Block $body,
        public readonly array $catches,
        public readonly ?Block $finally,
        int $line,
    ) {
        parent::__construct($line);
    }
}
