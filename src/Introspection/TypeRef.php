<?php

declare(strict_types=1);

namespace Cyonima\Introspection;

/** Référence de type issue de GITypeInfo. */
final readonly class TypeRef
{
    public function __construct(
        public int $tag,
        public bool $pointer,
        public ?string $namespace = null,
        public ?string $name = null,
        public ?int $interfaceKind = null,
        public ?TypeRef $element = null,
        public bool $hasArrayLength = false,
        public bool $hasFixedSize = false,
    ) {
    }

    public function isVoid(): bool
    {
        return $this->tag === Gi::TAG_VOID;
    }

    public function isBoolean(): bool
    {
        return $this->tag === Gi::TAG_BOOLEAN;
    }

    public function isString(): bool
    {
        return $this->tag === Gi::TAG_UTF8 || $this->tag === Gi::TAG_FILENAME;
    }

    public function isNumeric(): bool
    {
        return in_array($this->tag, [
            Gi::TAG_INT8, Gi::TAG_UINT8, Gi::TAG_INT16, Gi::TAG_UINT16,
            Gi::TAG_INT32, Gi::TAG_UINT32, Gi::TAG_INT64, Gi::TAG_UINT64,
            Gi::TAG_FLOAT, Gi::TAG_DOUBLE, Gi::TAG_GTYPE, Gi::TAG_UNICHAR,
        ], true);
    }

    public function isEnumLike(): bool
    {
        return $this->tag === Gi::TAG_INTERFACE
            && in_array($this->interfaceKind, [Gi::INFO_ENUM, Gi::INFO_FLAGS, Gi::INFO_BITFIELD], true);
    }

    public function isObjectLike(): bool
    {
        return $this->tag === Gi::TAG_INTERFACE
            && !$this->isEnumLike()
            && in_array($this->interfaceKind, [
                Gi::INFO_OBJECT, Gi::INFO_INTERFACE, Gi::INFO_BOXED,
                Gi::INFO_STRUCT, Gi::INFO_UNION,
            ], true);
    }

    public function isArray(): bool
    {
        return $this->tag === Gi::TAG_ARRAY
            || in_array($this->tag, [Gi::TAG_GLIST, Gi::TAG_GSLIST, Gi::TAG_GHASH], true);
    }

    public function fqcn(): ?string
    {
        if ($this->namespace === null || $this->name === null) {
            return null;
        }
        return $this->namespace . '\\' . $this->name;
    }

    /** Représentation courte pour les diagnostics. */
    public function describe(): string
    {
        $base = match (true) {
            $this->isVoid() => 'void',
            $this->isBoolean() => 'bool',
            $this->isString() => 'string',
            $this->isArray() => 'array<' . ($this->element?->describe() ?? '?') . '>',
            $this->isEnumLike() => ($this->fqcn() ?? 'enum'),
            $this->isObjectLike() => ($this->fqcn() ?? 'object'),
            default => 'tag' . $this->tag,
        };
        return $this->pointer && !$this->isString() ? $base . '*' : $base;
    }
}
