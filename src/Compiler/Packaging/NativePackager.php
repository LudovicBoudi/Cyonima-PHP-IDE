<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Packaging;

use Cyonima\Compiler\CompilerException;
use Cyonima\Compiler\Native\NativeModule;

/**
 * Packaging direct d’un binaire natif en AppImage ou Flatpak.
 */
final class NativePackager
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly string $repositoryRoot,
    ) {
    }

    /**
     * @param array<string, NativeModule> $modules
     * @return array{path: string, message: string}
     */
    public function build(
        string $format,
        string $binary,
        array $modules,
        ?string $output = null,
    ): array {
        if (!is_file($binary) || !is_executable($binary)) {
            throw new CompilerException('Binaire natif introuvable : ' . $binary);
        }

        $metadata = $this->metadata($binary);
        $suffix = $format === 'flatpak' ? 'x86_64.flatpak' : 'x86_64.AppImage';

        if ($format !== 'appimage' && $format !== 'flatpak') {
            throw new CompilerException('Format de packaging inconnu : ' . $format);
        }

        if ($output === null || $output === '') {
            $output = rtrim($this->projectRoot, '/') . '/dist/'
                . $metadata['slug'] . '-' . $metadata['version'] . '-' . $suffix;
        }

        $arguments = [
            $this->projectRoot,
            $binary,
            $metadata['name'],
            $metadata['slug'],
            $metadata['appId'],
            $metadata['version'],
            $output,
        ];

        if ($format === 'flatpak') {
            $script = $this->repositoryRoot
                . '/packaging/flatpak/build-native-project-flatpak.sh';
            $libraries = [];

            foreach ($modules as $module) {
                foreach ($module->flatpakLibraries() as $library) {
                    $libraries[$library] = true;
                }
            }

            $prefix = 'CYONIMA_NATIVE_LIBS=' . escapeshellarg(implode(' ', array_keys($libraries)))
                . ' PHP_CLI=' . escapeshellarg(PHP_BINARY) . ' ';
        } else {
            $script = $this->repositoryRoot
                . '/packaging/appimage/build-native-project.sh';
            $prefix = '';
        }

        $command = $prefix . 'bash ' . escapeshellarg($script);

        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        exec($command . ' 2>&1', $lines, $status);

        if ($status !== 0 || !is_file($output)) {
            throw new CompilerException(
                "Échec du packaging {$format} :\n" . implode("\n", $lines),
            );
        }

        return [
            'path' => $output,
            'message' => ($format === 'flatpak' ? 'Flatpak natif généré : ' : 'AppImage native générée : ')
                . $output,
        ];
    }

    /** @return array{name: string, slug: string, appId: string, version: string} */
    private function metadata(string $binary): array
    {
        $manifestPath = rtrim($this->projectRoot, '/') . '/.cyonima/project.json';
        $manifest = [];

        if (is_file($manifestPath)) {
            $decoded = json_decode((string) file_get_contents($manifestPath), true);

            if (is_array($decoded)) {
                $manifest = $decoded;
            }
        }

        $name = is_string($manifest['name'] ?? null) && trim($manifest['name']) !== ''
            ? trim($manifest['name'])
            : pathinfo($binary, PATHINFO_FILENAME);
        $slug = $this->slug($name);
        $appId = is_string($manifest['appId'] ?? null) && trim($manifest['appId']) !== ''
            ? trim($manifest['appId'])
            : 'com.example.' . str_replace('-', '', $slug);
        $version = is_string($manifest['version'] ?? null) && trim($manifest['version']) !== ''
            ? trim($manifest['version'])
            : '0.1.0';

        return [
            'name' => $name,
            'slug' => $slug,
            'appId' => $appId,
            'version' => $version,
        ];
    }

    private function slug(string $name): string
    {
        $ascii = strtr($name, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u',
            'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'œ' => 'oe', 'æ' => 'ae',
            'À' => 'a', 'Â' => 'a', 'Ä' => 'a', 'É' => 'e', 'È' => 'e', 'Ê' => 'e',
            'Ë' => 'e', 'Î' => 'i', 'Ï' => 'i', 'Ô' => 'o', 'Ö' => 'o', 'Ù' => 'u',
            'Û' => 'u', 'Ü' => 'u', 'Ç' => 'c', 'Œ' => 'oe', 'Æ' => 'ae',
        ]);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');

        return $slug === '' ? 'application' : $slug;
    }
}
