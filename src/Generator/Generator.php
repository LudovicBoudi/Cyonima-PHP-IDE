<?php

declare(strict_types=1);

namespace Cyonima\Generator;

use Cyonima\Introspection\CallableInfo;
use Cyonima\Introspection\EnumInfo;
use Cyonima\Introspection\ObjectInfo;
use Cyonima\Introspection\Reader;
use RuntimeException;

/**
 * Orchestre la génération : lit les typelibs, résout la sélection, émet les
 * fichiers et nettoie les artefacts obsolètes.
 */
final class Generator
{
    private const AUTOLOAD_ROOT = 'Cyonima\\';

    public function __construct(
        private readonly Spec $spec,
        private readonly Reader $reader = new Reader(),
    ) {
    }

    public function generate(): Report
    {
        $report = new Report();

        $objects = [];
        $enums = [];
        foreach ($this->spec->namespaces as $namespace => $config) {
            $scan = $this->reader->scan($namespace, $config['version']);
            foreach ($scan['objects'] as $object) {
                $objects[$object->fqcn()] = $object;
            }
            foreach ($scan['enums'] as $enum) {
                $enums[$enum->fqcn()] = $enum;
            }
        }

        $generatedClasses = [];
        foreach (array_keys($this->spec->classes) as $fqcn) {
            [$namespace, $name] = explode('\\', $fqcn, 2);
            $config = $this->spec->namespaceConfig($namespace);
            $generatedClasses[$fqcn] = '\\' . $config['raw_namespace'] . '\\' . $name;
        }

        $mapper = new TypeMapper($generatedClasses);
        $constructors = $this->constructorSignatures($objects, $mapper);
        $files = [];
        $symbolsByNamespace = [];

        foreach ($this->spec->classes as $fqcn => $selection) {
            $object = $objects[$fqcn]
                ?? throw new RuntimeException("Type introuvable dans les typelibs : {$fqcn}");

            [$namespace, $name] = explode('\\', $fqcn, 2);
            $config = $this->spec->namespaceConfig($namespace);
            $rawNamespace = $config['raw_namespace'];

            $parent = $this->resolveParent($object, $objects, $generatedClasses, $rawNamespace);

            [$methods, $signals] = $this->resolveMembers($object, $selection, $mapper, $report);

            $files[$this->pathFor($rawNamespace, $name)] = (new ClassEmitter(
                object: $object,
                methods: $methods,
                signals: $signals,
                parent: $parent,
                rawNamespace: $rawNamespace,
                version: $config['version'],
                mapper: $mapper,
                constructorName: $this->constructorName($fqcn, $objects, $constructors),
            ))->emit();

            $report->classes++;
            $report->methods += count($methods);
            $report->signals += count($signals);

            $symbolsByNamespace[$rawNamespace] ??= [
                'library' => $config['library'],
                'version' => $config['version'],
                'init' => $config['init'] ?? null,
                'declarations' => [],
            ];

            foreach ($methods as $entry) {
                $declaration = $this->cDeclaration($entry['callable'], $mapper);
                $symbolsByNamespace[$rawNamespace]['declarations'][] = $declaration;
            }
        }

        foreach ($this->spec->enums as $fqcn => $enabled) {
            if (!$enabled) {
                continue;
            }
            $enum = $enums[$fqcn] ?? throw new RuntimeException("Enum introuvable : {$fqcn}");
            [$namespace, $name] = explode('\\', $fqcn, 2);
            $rawNamespace = $this->spec->namespaceConfig($namespace)['raw_namespace'];
            $files[$this->pathFor($rawNamespace, $name)] = $this->emitEnum($enum, $rawNamespace);
            $report->enums++;
        }

        foreach ($symbolsByNamespace as $rawNamespace => $data) {
            $declarations = array_values(array_unique($data['declarations']));
            $files[$this->pathFor($rawNamespace, 'Symbols')] = (new SymbolsEmitter(
                rawNamespace: $rawNamespace,
                library: $data['library'],
                version: $data['version'],
                declarations: $declarations,
                initFunction: $data['init'],
            ))->emit();
        }

        $this->write($files, $report);

        return $report;
    }

    /**
     * @param array{methods: array<string, list<string>>, signals?: bool|list<string>} $selection
     * @return array{0: list<array{callable: CallableInfo, params: list<string>}>, 1: list<\Cyonima\Introspection\SignalInfo>}
     */
    private function resolveMembers(ObjectInfo $object, array $selection, TypeMapper $mapper, Report $report): array
    {
        $byName = [];
        foreach ($object->methods as $method) {
            $byName[$method->name] = $method;
        }

        $methods = [];
        foreach ($selection['methods'] as $methodName => $paramNames) {
            $method = $byName[$methodName]
                ?? throw new RuntimeException(sprintf(
                    '%s::%s absent du typelib (API modifiée ?)',
                    $object->fqcn(),
                    $methodName,
                ));

            $skipReason = $this->unsupportedReason($method, $mapper);
            if ($skipReason !== null) {
                $report->skipped[] = sprintf('%s::%s — %s', $object->fqcn(), $methodName, $skipReason);
                continue;
            }

            if (count($method->parameters) !== count($paramNames)) {
                throw new RuntimeException(sprintf(
                    '%s::%s attend %d paramètre(s), la spec en déclare %d',
                    $object->fqcn(),
                    $methodName,
                    count($method->parameters),
                    count($paramNames),
                ));
            }

            $methods[] = ['callable' => $method, 'params' => $paramNames];
        }

        $signalSelection = $selection['signals'] ?? false;
        $signals = [];
        if ($signalSelection !== false) {
            foreach ($object->signals as $signal) {
                if (is_array($signalSelection) && !in_array($signal->name, $signalSelection, true)) {
                    continue;
                }
                $signals[] = $signal;
            }
        }

        return [$methods, $signals];
    }

    /**
     * Signature PHP des constructeurs déclarés dans la spec, par classe.
     *
     * @param array<string, ObjectInfo> $objects
     * @return array<string, list<string>>
     */
    private function constructorSignatures(array $objects, TypeMapper $mapper): array
    {
        $signatures = [];

        foreach (array_keys($this->spec->classes) as $fqcn) {
            $object = $objects[$fqcn] ?? null;
            if ($object === null) {
                continue;
            }
            foreach ($object->methods as $method) {
                if ($method->isConstructor() && $method->name === 'new') {
                    $signatures[$fqcn] = array_map(
                        static fn ($parameter): string => $mapper->phpType($parameter->type),
                        $method->parameters,
                    );
                    break;
                }
            }
        }

        return $signatures;
    }

    /**
     * Un constructeur qui redéfinit un constructeur ancêtre avec une signature
     * différente ne peut pas s'appeler `new` en PHP (LSP). Il devient `create`.
     *
     * @param array<string, ObjectInfo> $objects
     * @param array<string, list<string>> $constructors
     */
    private function constructorName(string $fqcn, array $objects, array $constructors): string
    {
        $signature = $constructors[$fqcn] ?? null;
        if ($signature === null) {
            return 'new';
        }

        $seen = [];
        $parent = $objects[$fqcn]->parentFqcn ?? null;

        while ($parent !== null && !isset($seen[$parent])) {
            $seen[$parent] = true;
            if (isset($constructors[$parent]) && $constructors[$parent] !== $signature) {
                return 'create';
            }
            $parent = $objects[$parent]->parentFqcn ?? null;
        }

        return 'new';
    }

    /**
     * Remonte la chaîne d'héritage jusqu'au premier ancêtre effectivement
     * généré. Exemple : Adw\PreferencesRow (Gtk\ListBoxRow non généré) doit
     * tout de même étendre Gtk\Widget.
     *
     * @param array<string, ObjectInfo> $objects
     * @param array<string, string> $generatedClasses
     */
    private function resolveParent(
        ObjectInfo $object,
        array $objects,
        array $generatedClasses,
        string $rawNamespace,
    ): string {
        $seen = [];
        $parent = $object->parentFqcn;

        while ($parent !== null && !isset($seen[$parent])) {
            $seen[$parent] = true;
            if (isset($generatedClasses[$parent])) {
                return $generatedClasses[$parent];
            }
            $parent = $objects[$parent]->parentFqcn ?? null;
        }

        return $rawNamespace === 'Cyonima\\Gtk\\Raw'
            ? 'RawObject'
            : '\\Cyonima\\Gtk\\Raw\\RawObject';
    }

    private function unsupportedReason(CallableInfo $method, TypeMapper $mapper): ?string
    {
        if (!$method->hasOnlyInputParameters()) {
            return 'paramètre de sortie (out/inout)';
        }
        if ($method->throws) {
            return 'erreur GError';
        }
        if (!$mapper->supports($method->returnType)) {
            return 'retour : ' . $mapper->unsupportedReason($method->returnType);
        }
        foreach ($method->parameters as $parameter) {
            if (!$mapper->supports($parameter->type)) {
                return sprintf('paramètre $%s : %s', $parameter->name, $mapper->unsupportedReason($parameter->type));
            }
        }
        return null;
    }

    private function cDeclaration(CallableInfo $method, TypeMapper $mapper): string
    {
        $symbol = $method->symbol ?? throw new RuntimeException('Méthode sans symbole : ' . $method->name);
        $arguments = [];

        if ($method->isMethod()) {
            $arguments[] = 'void *self';
        }
        foreach ($method->parameters as $index => $parameter) {
            $arguments[] = $mapper->cType($parameter->type) . ' arg' . $index;
        }

        return sprintf(
            '%s %s(%s);',
            $mapper->cType($method->returnType),
            $symbol,
            $arguments === [] ? 'void' : implode(', ', $arguments),
        );
    }

    private function emitEnum(EnumInfo $enum, string $rawNamespace): string
    {
        $identifiers = [];
        foreach ($enum->values as $nick => $value) {
            $identifiers[$nick] = $enum->identifiers[$nick] ?? strtoupper($nick);
        }

        $prefix = $this->commonIdentifierPrefix(array_values($identifiers));

        $constants = [];
        foreach ($enum->values as $nick => $value) {
            $identifier = $identifiers[$nick];
            $name = $prefix !== '' && str_starts_with($identifier, $prefix)
                ? substr($identifier, strlen($prefix))
                : $identifier;
            $constants[$name] = $value;
        }

        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            '/**',
            ' * Fichier généré depuis la spec des bindings — ne pas éditer.',
            ' */',
            '',
            'namespace ' . $rawNamespace . ';',
            '',
            'final class ' . $enum->name,
            '{',
        ];

        foreach ($constants as $name => $value) {
            $lines[] = sprintf('    public const %s = %d;', $name, $value);
        }

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Plus long préfixe commun, tronqué au dernier `_` : les identifiants C
     * (`ADW_CENTERING_POLICY_LOOSE`) ne suivent pas toujours le nom du type
     * (`CenteringPolicy`).
     *
     * @param list<string> $identifiers
     */
    private function commonIdentifierPrefix(array $identifiers): string
    {
        if ($identifiers === []) {
            return '';
        }

        $prefix = $identifiers[0];
        foreach ($identifiers as $identifier) {
            while ($prefix !== '' && !str_starts_with($identifier, $prefix)) {
                $prefix = substr($prefix, 0, -1);
            }
        }

        $cut = strrpos($prefix, '_');
        return $cut === false ? '' : substr($prefix, 0, $cut + 1);
    }

    private function pathFor(string $rawNamespace, string $name): string
    {
        $relative = str_replace('\\', '/', substr($rawNamespace, strlen(self::AUTOLOAD_ROOT)));
        return rtrim($this->spec->output, '/') . '/' . $relative . '/' . $name . '.php';
    }

    /** @param array<string, string> $files */
    private function write(array $files, Report $report): void
    {
        $output = rtrim($this->spec->output, '/');
        $manifestPath = $output . '/.generated.json';
        $previous = [];
        if (is_file($manifestPath)) {
            $decoded = json_decode((string) file_get_contents($manifestPath), true);
            $previous = is_array($decoded) ? $decoded : [];
        }

        $written = [];
        foreach ($files as $path => $content) {
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException("Impossible de créer {$directory}");
            }
            file_put_contents($path, $content);
            $written[] = substr($path, strlen($output) + 1);
            $report->files[] = $path;
        }

        foreach (array_diff($previous, $written) as $stale) {
            $stalePath = $output . '/' . $stale;
            if (is_file($stalePath)) {
                unlink($stalePath);
            }
        }

        file_put_contents(
            $manifestPath,
            json_encode($written, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }
}
