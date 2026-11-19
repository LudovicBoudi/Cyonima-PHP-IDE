<?php

declare(strict_types=1);

namespace Cyonima\Generator;

use InvalidArgumentException;

/**
 * Description déclarative de la surface de bindings à générer.
 *
 * Format d'un fichier de spec :
 *
 *   return [
 *       'output' => 'src',
 *       'namespaces' => [
 *           'Gtk' => ['version' => '4.0', 'library' => 'libgtk-4.so.1', 'raw_namespace' => 'Cyonima\\Gtk\\Raw'],
 *       ],
 *       'classes' => [
 *           'Gtk\\Window' => [
 *               'methods' => ['new' => [], 'set_title' => ['title']],
 *               'signals' => true,
 *           ],
 *       ],
 *       'enums' => ['Gtk\\Orientation' => true],
 *   ];
 */
final readonly class Spec
{
    /**
     * @param array<string, array{version: string, library: string, raw_namespace: string}> $namespaces
     * @param array<string, array{methods: array<string, list<string>>, signals?: bool|list<string>}> $classes
     * @param array<string, bool> $enums
     */
    public function __construct(
        public string $output,
        public array $namespaces,
        public array $classes,
        public array $enums,
    ) {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException("Spec introuvable : {$path}");
        }

        /** @var array{output?: string, namespaces?: array, classes?: array, enums?: array} $config */
        $config = require $path;

        if (!isset($config['namespaces'], $config['classes']) || $config['namespaces'] === []) {
            throw new InvalidArgumentException("Spec invalide : 'namespaces' et 'classes' sont requis.");
        }

        // Les chemins de sortie sont relatifs à la racine du projet
        // (convention : les specs vivent dans <racine>/spec/).
        $output = $config['output'] ?? 'src';
        if (!str_starts_with($output, DIRECTORY_SEPARATOR)) {
            $output = dirname($path, 2) . '/' . $output;
        }

        return new self(
            output: $output,
            namespaces: $config['namespaces'],
            classes: $config['classes'],
            enums: $config['enums'] ?? [],
        );
    }

    /** @return array{version: string, library: string, raw_namespace: string} */
    public function namespaceConfig(string $namespace): array
    {
        if (!isset($this->namespaces[$namespace])) {
            throw new InvalidArgumentException("Namespace absent de la spec : {$namespace}");
        }
        return $this->namespaces[$namespace];
    }

    /** @return list<string> */
    public function namespaces(): array
    {
        return array_keys($this->namespaces);
    }
}
