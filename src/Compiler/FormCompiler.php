<?php

declare(strict_types=1);

namespace Cyonima\Compiler;

use Cyonima\Runtime\FormDocument;
use Cyonima\Runtime\ProjectFactory;

/**
 * Compile un formulaire du designer en C puis, si disponible, en binaire ELF.
 */
final class FormCompiler
{
    public function __construct(private readonly string $projectRoot)
    {
    }

    /** @return array{ok: bool, message: string, cPath: string, binaryPath: null|string} */
    public function compile(
        string $formName = 'main-form',
        ?string $output = null,
        string $emit = 'elf',
    ): array {
        $project = rtrim($this->projectRoot, '/');
        $form = FormDocument::load($project, $formName);

        if ($form === null) {
            throw new CompilerException('Formulaire introuvable : ' . $formName);
        }

        $manifest = ProjectFactory::manifest($project);
        $name = is_string($manifest['name'] ?? null) && $manifest['name'] !== ''
            ? $manifest['name']
            : basename($project);
        $appId = is_string($manifest['appId'] ?? null) && $manifest['appId'] !== ''
            ? $manifest['appId']
            : ProjectFactory::suggestAppId($name);

        $slug = ProjectFactory::slug($form->name());
        $nativeDir = $project . '/dist/native';
        $this->ensureDirectory($nativeDir);

        $cPath = $nativeDir . '/' . $slug . '.c';
        $generator = new CGenerator($form, $project, $appId, $name);
        $this->write($cPath, $generator->generate());

        if ($output === null || $output === '') {
            $output = $project . '/dist/' . $slug;
        }

        if ($emit === 'c') {
            return [
                'ok' => true,
                'message' => 'Code C généré : ' . $cPath,
                'cPath' => $cPath,
                'binaryPath' => null,
            ];
        }

        if ($emit !== 'elf') {
            throw new CompilerException('Sortie inconnue : ' . $emit);
        }

        $this->ensureDirectory(dirname($output));
        $flags = shell_exec('pkg-config --cflags --libs gtk4 2>/dev/null');

        if (!is_string($flags) || trim($flags) === '') {
            throw new CompilerException(
                'GTK4 de développement introuvable. Installez libgtk-4-dev ou utilisez --emit=c.',
            );
        }

        $command = sprintf(
            'gcc -std=c11 -O2 -o %s %s %s 2>&1',
            escapeshellarg($output),
            escapeshellarg($cPath),
            $flags,
        );

        exec($command, $lines, $status);
        if ($status !== 0) {
            throw new CompilerException("Échec de la compilation C :\n" . implode("\n", $lines));
        }

        return [
            'ok' => true,
            'message' => 'Binaire natif généré : ' . $output,
            'cPath' => $cPath,
            'binaryPath' => $output,
        ];
    }

    private function write(string $path, string $content): void
    {
        if (file_put_contents($path, $content) === false) {
            throw new CompilerException('Écriture impossible : ' . $path);
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new CompilerException('Dossier impossible à créer : ' . $directory);
        }
    }
}
