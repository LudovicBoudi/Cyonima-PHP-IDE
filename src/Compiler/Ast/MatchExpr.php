<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class MatchExpr extends Expr
{
    /** @param list<MatchArm> $arms */
    public function __construct(
        public readonly Expr $subject,
        public readonly array $arms,
        int $line,
    ) {
        parent::__construct($line);
    }
}
