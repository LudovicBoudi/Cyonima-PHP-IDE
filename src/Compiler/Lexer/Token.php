<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Lexer;

final class Token
{
    public function __construct(
        public readonly TokenType $type,
        public readonly string $lexeme,
        public readonly mixed $literal,
        public readonly int $line,
        public readonly ?string $docComment = null,
    ) {
    }

    public function description(): string
    {
        return $this->type->value . ($this->lexeme === '' ? '' : ' « ' . $this->lexeme . ' »');
    }
}
