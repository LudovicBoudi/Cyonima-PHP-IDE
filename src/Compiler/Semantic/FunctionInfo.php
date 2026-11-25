<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Semantic;

final class FunctionInfo
{
    /** @var array<string, string> */
    public array $variables = [];

    /** @param array<string, string> $params */
    public function __construct(
        public readonly string $name,
        public readonly array $params,
        public readonly string $returnType,
    ) {
    }
}
