<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Ast;

final class ClosureExpr extends Expr
{
    /** @var array<string, string> */
    public array $captureTypes = [];

    /** @var array<string, string> */
    public array $variables = [];

    /**
     * @param list<Param> $params
     * @param list<string> $use
     */
    public function __construct(
        public readonly bool $arrow,
        public readonly array $params,
        public readonly ?string $returnType,
        public readonly ?Block $body,
        public readonly ?Expr $expression,
        public readonly array $use,
        int $line,
    ) {
        parent::__construct($line);
    }
}
