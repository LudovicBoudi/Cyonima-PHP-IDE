<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Semantic;

final class Analysis
{
    /**
     * @param array<string, FunctionInfo> $functions
     * @param array<string, ClassInfo> $classes
     * @param array<string, string> $mainVariables
     */
    public function __construct(
        public readonly array $functions,
        public readonly array $classes,
        public readonly array $mainVariables,
    ) {
    }
}
