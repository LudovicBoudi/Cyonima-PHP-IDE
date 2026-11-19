<?php

declare(strict_types=1);

namespace Cyonima\Generator;

use Cyonima\Introspection\Gi;
use Cyonima\Introspection\TypeRef;

/**
 * Traduit les types GObject Introspection vers le C (pour la cdef) et vers PHP.
 *
 * Les objets GObject sont volontairement représentés par `void*` des deux côtés
 * de la frontière : le typage vit dans les classes PHP, pas dans la cdef. Cela
 * évite toute dépendance aux définitions de structs de GTK.
 */
final class TypeMapper
{
    /**
     * @param array<string, string> $generatedClasses fqcn typelib => fqcn PHP
     */
    public function __construct(private array $generatedClasses)
    {
    }

    public function isGeneratedObject(TypeRef $type): bool
    {
        $fqcn = $type->fqcn();
        return $fqcn !== null && isset($this->generatedClasses[$fqcn]);
    }

    public function isOpaqueObject(TypeRef $type): bool
    {
        return $type->isObjectLike() && !$this->isGeneratedObject($type);
    }

    public function supports(TypeRef $type): bool
    {
        if ($type->isVoid() || $type->isBoolean() || $type->isString() || $type->isNumeric()) {
            return true;
        }
        if ($type->isEnumLike()) {
            return true;
        }
        if ($this->isGeneratedObject($type)) {
            return $type->pointer;
        }
        if ($this->isOpaqueObject($type)) {
            return $type->pointer;
        }
        return false;
    }

    public function unsupportedReason(TypeRef $type): string
    {
        if ($type->isArray()) {
            return 'tableau/liste';
        }
        if ($type->tag === Gi::TAG_INTERFACE && !$type->pointer) {
            return 'struct passé par valeur';
        }
        if ($type->interfaceKind === Gi::INFO_CALLBACK) {
            return 'callback';
        }
        return 'type non supporté (tag ' . $type->tag . ')';
    }

    public function cType(TypeRef $type): string
    {
        return match (true) {
            $type->isVoid() => 'void',
            $type->isBoolean() => 'int',
            $type->isString() => 'const char*',
            $type->isEnumLike() => 'int',
            $type->isObjectLike() => 'void*',
            $type->tag === Gi::TAG_FLOAT => 'float',
            $type->tag === Gi::TAG_DOUBLE => 'double',
            $type->tag === Gi::TAG_GTYPE => 'unsigned long',
            $type->tag === Gi::TAG_UNICHAR => 'unsigned int',
            $type->tag === Gi::TAG_INT64, $type->tag === Gi::TAG_UINT64 => 'long',
            $type->isNumeric() => 'int',
            default => throw new \LogicException('Type C non mappable : ' . $type->describe()),
        };
    }

    public function phpType(TypeRef $type): string
    {
        return match (true) {
            $type->isVoid() => 'void',
            $type->isBoolean() => 'bool',
            $type->isString() => '?string',
            $type->isEnumLike() => 'int',
            $this->isGeneratedObject($type) => '?' . $this->generatedClasses[$type->fqcn()],
            $this->isOpaqueObject($type) => '?\\FFI\\CData',
            $type->tag === Gi::TAG_FLOAT, $type->tag === Gi::TAG_DOUBLE => 'float',
            $type->isNumeric() => 'int',
            default => throw new \LogicException('Type PHP non mappable : ' . $type->describe()),
        };
    }

    /** Convertit une valeur PHP en argument accepté par la cdef. */
    public function unwrap(string $expression, TypeRef $type): string
    {
        if ($type->isBoolean()) {
            return "({$expression}) ? 1 : 0";
        }
        if ($this->isGeneratedObject($type)) {
            return "{$expression}?->handle()";
        }
        return $expression;
    }

    /** Convertit une valeur retournée par la cdef en valeur PHP typée. */
    public function wrapReturn(string $expression, TypeRef $type, int $transfer): string
    {
        if ($type->isBoolean()) {
            return "({$expression}) !== 0";
        }
        if ($this->isGeneratedObject($type)) {
            $owned = $transfer === Gi::TRANSFER_EVERYTHING ? 'true' : 'false';
            return sprintf(
                '%s === null ? null : %s::wrap(%s, %s)',
                $expression,
                $this->generatedClasses[$type->fqcn()],
                $expression,
                $owned,
            );
        }
        return $expression;
    }
}
