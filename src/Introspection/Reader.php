<?php

declare(strict_types=1);

namespace Cyonima\Introspection;

use FFI\CData;

/**
 * Convertit les pointeurs GIRepository en objets de valeur PHP.
 * Toute la connaissance du C (pointeurs, tags, flags) reste confinée ici.
 */
final class Reader
{
    public function __construct(private Gi $gi = new Gi())
    {
    }

    public function gi(): Gi
    {
        return $this->gi;
    }

    public function type(CData $typeInfo): TypeRef
    {
        $tag = $this->gi->typeTag($typeInfo);
        $pointer = $this->gi->typeIsPointer($typeInfo);

        $element = null;
        $hasArrayLength = false;
        $hasFixedSize = false;
        if ($tag === Gi::TAG_ARRAY) {
            $param = $this->gi->typeParam($typeInfo, 0);
            if ($param !== null) {
                $element = $this->type($param);
            }
            $hasArrayLength = $this->gi->typeArrayLength($typeInfo) >= 0;
            $hasFixedSize = $this->gi->typeArrayFixedSize($typeInfo) >= 0;
        }

        $namespace = $name = null;
        $kind = null;
        if ($tag === Gi::TAG_INTERFACE) {
            $interface = $this->gi->typeInterface($typeInfo);
            if ($interface !== null) {
                $namespace = $this->gi->infoNamespace($interface);
                $name = $this->gi->infoName($interface);
                $kind = $this->gi->infoType($interface);
            }
        }

        return new TypeRef(
            tag: $tag,
            pointer: $pointer,
            namespace: $namespace,
            name: $name,
            interfaceKind: $kind,
            element: $element,
            hasArrayLength: $hasArrayLength,
            hasFixedSize: $hasFixedSize,
        );
    }

    public function parameter(CData $arg, string $name = ''): Parameter
    {
        return new Parameter(
            name: $name,
            type: $this->type($this->gi->argType($arg)),
            direction: $this->gi->argDirection($arg),
            transfer: $this->gi->argTransfer($arg),
            nullable: $this->gi->argMayBeNull($arg),
            optional: $this->gi->argIsOptional($arg),
            callerAllocates: $this->gi->argIsCallerAllocates($arg),
        );
    }

    public function callable(CData $info, ?string $name = null, ?string $symbol = null): CallableInfo
    {
        $name ??= $this->gi->infoName($info);

        $parameters = [];
        foreach ($this->gi->args($info) as $arg) {
            if ($this->gi->argIsReturnValue($arg)) {
                continue;
            }
            $parameters[] = $this->parameter($arg);
        }
        $parameters = $this->nameParameters($name, $symbol, $parameters);

        return new CallableInfo(
            name: $name,
            symbol: $symbol,
            flags: $this->gi->functionFlags($info),
            returnType: $this->type($this->gi->returnType($info)),
            parameters: $parameters,
            callerOwns: $this->gi->callerOwns($info),
            mayReturnNull: $this->gi->mayReturnNull($info),
            throws: ($this->gi->functionFlags($info) & Gi::FUNCTION_THROWS) !== 0,
            deprecated: $this->gi->isDeprecated($info),
        );
    }

    public function signal(CData $info): SignalInfo
    {
        $parameters = [];
        foreach ($this->gi->args($info) as $arg) {
            if ($this->gi->argIsReturnValue($arg)) {
                continue;
            }
            $parameters[] = $this->parameter($arg);
        }
        $parameters = $this->nameParameters($this->gi->signalName($info), null, $parameters);

        return new SignalInfo(
            name: $this->gi->signalName($info),
            parameters: $parameters,
            returnType: $this->type($this->gi->returnType($info)),
            deprecated: $this->gi->isDeprecated($info),
        );
    }

    /**
     * Le typelib ne stocke pas les noms d'arguments : on les dérive du nom de
     * méthode, et la spec peut les surcharger.
     *
     * @param list<Parameter> $parameters
     * @return list<Parameter>
     */
    private function nameParameters(string $method, ?string $symbol, array $parameters): array
    {
        $names = [];
        $count = count($parameters);

        if (str_starts_with($method, 'set_') && $count >= 1) {
            $names[] = self::camel(substr($method, 4));
        } elseif (($pos = strpos($method, 'new_with_')) !== false && $count >= 1) {
            $names[] = self::camel(substr($method, $pos + 9));
        } elseif (($pos = strpos($method, 'with_')) !== false && $count >= 1 && !str_contains($method, 'with')) {
            $names[] = self::camel(substr($method, $pos + 5));
        }

        $i = 1;
        while (count($names) < $count) {
            $names[] = 'arg' . $i++;
        }

        $out = [];
        foreach ($parameters as $index => $parameter) {
            $out[] = new Parameter(
                name: $names[$index] ?? ('arg' . ($index + 1)),
                type: $parameter->type,
                direction: $parameter->direction,
                transfer: $parameter->transfer,
                nullable: $parameter->nullable,
                optional: $parameter->optional,
                callerAllocates: $parameter->callerAllocates,
            );
        }
        return $out;
    }

    private static function camel(string $snake): string
    {
        $camel = str_replace('_', '', ucwords($snake, '_'));
        return $camel === '' ? 'arg' : lcfirst($camel);
    }

    public function object(CData $info): ObjectInfo
    {
        $kind = $this->gi->infoType($info);
        $isInterface = $kind === Gi::INFO_INTERFACE;

        $methods = [];
        foreach ($this->gi->methods($info, $isInterface) as $method) {
            $methods[] = $this->callable(
                $method,
                $this->gi->infoName($method),
                $this->gi->functionSymbol($method),
            );
        }

        $signals = [];
        foreach ($this->gi->signals($info, $isInterface) as $signal) {
            $signals[] = $this->signal($signal);
        }

        $parent = $kind === Gi::INFO_OBJECT ? $this->gi->parent($info) : null;

        return new ObjectInfo(
            namespace: $this->gi->infoNamespace($info),
            name: $this->gi->infoName($info),
            kind: $kind,
            parentFqcn: $parent === null
                ? null
                : $this->gi->infoNamespace($parent) . '\\' . $this->gi->infoName($parent),
            methods: $methods,
            signals: $signals,
            deprecated: $this->gi->isDeprecated($info),
            typeName: $kind === Gi::INFO_OBJECT ? $this->gi->objectTypeName($info) : null,
        );
    }

    public function enum(CData $info): EnumInfo
    {
        $values = [];
        $identifiers = [];
        foreach ($this->gi->enumValueInfos($info) as $valueInfo) {
            $nick = $this->gi->infoName($valueInfo);
            $values[$nick] = $this->gi->enumValue($valueInfo);
            $identifier = $this->gi->infoAttribute($valueInfo, 'c:identifier');
            if ($identifier !== null) {
                $identifiers[$nick] = $identifier;
            }
        }

        return new EnumInfo(
            namespace: $this->gi->infoNamespace($info),
            name: $this->gi->infoName($info),
            isFlags: $this->gi->infoType($info) === Gi::INFO_FLAGS,
            values: $values,
            identifiers: $identifiers,
            deprecated: $this->gi->isDeprecated($info),
        );
    }

    /**
     * @return array{objects: list<ObjectInfo>, enums: list<EnumInfo>, functions: list<CallableInfo>}
     */
    public function scan(string $namespace, string $version): array
    {
        $this->gi->require($namespace, $version);

        $objects = $enums = $functions = [];
        for ($i = 0, $n = $this->gi->count($namespace); $i < $n; $i++) {
            $info = $this->gi->info($namespace, $i);
            match ($this->gi->infoType($info)) {
                Gi::INFO_OBJECT, Gi::INFO_INTERFACE => $objects[] = $this->object($info),
                Gi::INFO_ENUM, Gi::INFO_FLAGS, Gi::INFO_BITFIELD => $enums[] = $this->enum($info),
                Gi::INFO_FUNCTION => $functions[] = $this->callable(
                    $info,
                    $this->gi->infoName($info),
                    $this->gi->functionSymbol($info),
                ),
                default => null,
            };
        }

        return ['objects' => $objects, 'enums' => $enums, 'functions' => $functions];
    }
}
