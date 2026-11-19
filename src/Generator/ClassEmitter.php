<?php

declare(strict_types=1);

namespace Cyonima\Generator;

use Cyonima\Introspection\CallableInfo;
use Cyonima\Introspection\ObjectInfo;
use Cyonima\Introspection\SignalInfo;

/** Émet le code PHP d'une classe de bindings bruts. */
final class ClassEmitter
{
    /**
     * @param list<array{callable: CallableInfo, params: list<string>}> $methods
     * @param list<SignalInfo> $signals
     */
    public function __construct(
        private readonly ObjectInfo $object,
        private readonly array $methods,
        private readonly array $signals,
        private readonly string $parent,
        private readonly string $rawNamespace,
        private readonly string $version,
        private readonly TypeMapper $mapper,
    ) {
    }

    public function emit(): string
    {
        $name = $this->object->name;
        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'declare(strict_types=1);';
        $lines[] = '';
        $lines[] = '/**';
        $lines[] = sprintf(" * Fichier généré depuis %s-%s.typelib — ne pas éditer.", $this->object->namespace, $this->version);
        $lines[] = ' * Régénération : php bin/cyonima-gen generate';
        $lines[] = ' */';
        $lines[] = '';
        $lines[] = 'namespace ' . $this->rawNamespace . ';';
        $lines[] = '';
        $lines[] = sprintf('class %s extends %s', $name, $this->parent);
        $lines[] = '{';
        $lines[] = sprintf(
            "    public const TYPE_NAME = '%s';",
            $this->object->typeName ?? $name,
        );

        if ($this->signals !== []) {
            $lines[] = '';
            foreach ($this->signals as $signal) {
                $lines[] = sprintf(
                    "    public const SIGNAL_%s = '%s';",
                    self::constantName($signal->name),
                    $signal->name,
                );
            }
        }

        foreach ($this->methods as $entry) {
            $lines[] = '';
            array_push($lines, ...$this->methodLines($entry['callable'], $entry['params']));
        }

        foreach ($this->signals as $signal) {
            $lines[] = '';
            array_push($lines, ...$this->signalLines($signal));
        }

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $paramNames
     * @return list<string>
     */
    private function methodLines(CallableInfo $method, array $paramNames): array
    {
        $phpParams = [];
        foreach ($method->parameters as $index => $parameter) {
            $phpParams[] = sprintf(
                '%s $%s',
                $this->mapper->phpType($parameter->type),
                $paramNames[$index],
            );
        }

        $signatureParams = implode(', ', $phpParams);
        $symbol = $method->symbol ?? throw new \LogicException('Méthode sans symbole : ' . $method->name);
        $returnType = $this->mapper->phpType($method->returnType);
        $isStatic = $method->isConstructor() || (!$method->isMethod() && !$method->isConstructor());

        $name = self::methodName($method->name);

        if ($method->isConstructor()) {
            return [
                sprintf('    public static function %s(%s): self', $name, $signatureParams),
                '    {',
                '        Symbols::ensureInitialized();',
                sprintf('        $pointer = Symbols::get()->%s(%s);', $symbol, $this->callArguments($method, $paramNames, false)),
                '        return new self(self::adopt($pointer));',
                '    }',
            ];
        }

        $modifier = $isStatic ? 'public static' : 'public';
        $lines = [sprintf('    %s function %s(%s): %s', $modifier, $name, $signatureParams, $returnType)];
        $lines[] = '    {';

        $arguments = $this->callArguments($method, $paramNames, !$isStatic);
        $call = sprintf('Symbols::get()->%s(%s)', $symbol, $arguments);

        if ($returnType === 'void') {
            $lines[] = "        {$call};";
        } else {
            $wrapped = $this->mapper->wrapReturn('$result', $method->returnType, $method->callerOwns);
            $lines[] = "        \$result = {$call};";
            $lines[] = "        return {$wrapped};";
        }

        $lines[] = '    }';
        return $lines;
    }

    /** @return list<string> */
    private function signalLines(SignalInfo $signal): array
    {
        $arity = count($signal->parameters);
        return [
            sprintf(
                '    public function on%s(callable $handler): int',
                ucfirst(self::camel($signal->name)),
            ),
            '    {',
            sprintf(
                '        return $this->connectSignal(self::SIGNAL_%s, $handler, %d);',
                self::constantName($signal->name),
                $arity,
            ),
            '    }',
        ];
    }

    /**
     * @param list<string> $paramNames
     */
    private function callArguments(CallableInfo $method, array $paramNames, bool $withSelf): string
    {
        $arguments = $withSelf ? ['$this->handle'] : [];
        foreach ($method->parameters as $index => $parameter) {
            $arguments[] = $this->mapper->unwrap('$' . $paramNames[$index], $parameter->type);
        }
        return implode(', ', $arguments);
    }

    public static function methodName(string $snake): string
    {
        return self::camel($snake);
    }

    public static function camel(string $snake): string
    {
        $camel = str_replace(['_', '-'], '', ucwords(str_replace('-', '_', $snake), '_'));
        return $camel === '' ? 'arg' : lcfirst($camel);
    }

    public static function constantName(string $snake): string
    {
        return strtoupper(str_replace('-', '_', $snake));
    }
}
