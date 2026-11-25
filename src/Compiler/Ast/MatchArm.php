<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class MatchArm extends Node
{
    /**
     * @param list<Expr> $conditions vide pour l’arm `default`
     */
    public function __construct(
        public readonly array $conditions,
        public readonly Expr $body,
        int $line,
    ) {
        parent::__construct($line);
    }
}
