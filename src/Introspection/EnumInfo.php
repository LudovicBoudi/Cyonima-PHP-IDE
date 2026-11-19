<?php

declare(strict_types=1);

namespace Cyonima\Introspection;

final readonly class EnumInfo
{
    /**
     * @param array<string, int> $values
     * @param array<string, string> $identifiers nick → identifiant C (ex. GTK_ORIENTATION_HORIZONTAL)
     */
    public function __construct(
        public string $namespace,
        public string $name,
        public bool $isFlags,
        public array $values,
        public array $identifiers = [],
        public bool $deprecated = false,
    ) {
    }

    public function fqcn(): string
    {
        return $this->namespace . '\\' . $this->name;
    }
}
