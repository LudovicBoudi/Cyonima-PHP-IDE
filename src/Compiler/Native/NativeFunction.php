<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Native;

/**
 * Signature d’une fonction fournie par un module natif.
 *
 * Le nom C généré suit la convention du backend : `fn_<nom PHP>`.
 */
final class NativeFunction
{
    /** @param array<string, string> $params */
    public function __construct(
        public readonly string $name,
        public readonly array $params,
        public readonly string $returnType,
    ) {
    }

    public function cName(): string
    {
        return 'fn_' . preg_replace('/[^A-Za-z0-9_]/', '_', $this->name);
    }
}
