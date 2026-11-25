<?php

declare(strict_types=1);

namespace Cyonima\Compiler;

use Cyonima\Compiler\Backend\C\PhpCGenerator;
use Cyonima\Compiler\Ast\Program;
use Cyonima\Compiler\Ir\IrBuilder;
use Cyonima\Compiler\Lexer\Lexer;
use Cyonima\Compiler\Lexer\TokenType;
use Cyonima\Compiler\Parser\Parser;
use Cyonima\Compiler\Semantic\SemanticAnalyzer;

/**
 * Compile un fichier PHP du sous-ensemble supporté vers C11 puis ELF.
 *
 * Le pipeline est strictement séparé : lexer → parser → analyse sémantique →
 * IR → backend C. Une construction hors périmètre échoue avant toute
 * génération, avec le numéro de ligne.
 */
final class PhpCompiler
{
    public function __construct(private readonly string $projectRoot)
    {
    }

    /** @return array{ok: bool, message: string, cPath: string, binaryPath: null|string} */
    public function compile(
        string $sourcePath,
        ?string $output = null,
        string $emit = 'elf',
    ): array {
        $sourcePath = realpath($sourcePath) ?: $sourcePath;

        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new CompilerException('Fichier PHP introuvable : ' . $sourcePath);
        }

        $source = file_get_contents($sourcePath);

        if ($source === false) {
            throw new CompilerException('Lecture impossible : ' . $sourcePath);
        }

        $tokens = (new Lexer($source))->tokenize();
        $program = (new Parser($tokens))->parse();

        if ($this->usesUiClasses($tokens)) {
            $prelude = (new Parser((new Lexer(UiPrelude::SOURCE))->tokenize()))->parse();
            $program = new Program(
                array_merge($prelude->functions, $program->functions),
                array_merge($prelude->classes, $program->classes),
                array_merge($prelude->interfaces, $program->interfaces),
                $program->statements,
                $program->line,
            );
        }

        $analysis = (new SemanticAnalyzer())->analyze($program);
        $ir = (new IrBuilder($analysis))->build($program);
        $generator = new PhpCGenerator();
        $ui = $generator->usesUi($ir);
        $cSource = $generator->generate($ir, $ui, $analysis->classes);

        $project = rtrim($this->projectRoot, '/');
        $nativeDir = $project . '/dist/native';
        $this->ensureDirectory($nativeDir);

        $slug = $this->slug(pathinfo($sourcePath, PATHINFO_FILENAME));
        $cPath = $nativeDir . '/' . $slug . '.php.c';
        $this->write($cPath, $cSource);

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

        if ($output === null || $output === '') {
            $output = $project . '/dist/' . $slug;
        }

        $this->ensureDirectory(dirname($output));
        $gcc = trim((string) shell_exec('command -v gcc 2>/dev/null'));

        if ($gcc === '') {
            throw new CompilerException('gcc introuvable ; utilisez --emit=c pour générer le C.');
        }

        $runtime = dirname(__DIR__, 2) . '/runtime/native';
        $gtkFlags = '';

        if ($ui) {
            $gtkFlags = trim((string) shell_exec('pkg-config --cflags --libs gtk4 2>/dev/null'));

            if ($gtkFlags === '') {
                throw new CompilerException(
                    'GTK4 de développement introuvable ; installez libgtk-4-dev ou utilisez --emit=c.',
                );
            }
        }

        $command = sprintf(
            '%s -std=c11 -O2 -o %s %s -I %s %s -lm 2>&1',
            escapeshellarg($gcc),
            escapeshellarg($output),
            escapeshellarg($cPath),
            escapeshellarg($runtime),
            $gtkFlags,
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

    /** @param list<\Cyonima\Compiler\Lexer\Token> $tokens */
    private function usesUiClasses(array $tokens): bool
    {
        $classes = ['Application', 'Widget', 'Window', 'Box', 'Label', 'Button', 'Entry'];
        $count = count($tokens);

        for ($index = 0; $index < $count - 1; $index++) {
            if ($tokens[$index]->type === TokenType::NewKeyword
                && $tokens[$index + 1]->type === TokenType::Identifier
                && in_array($tokens[$index + 1]->literal, $classes, true)) {
                return true;
            }
        }

        return false;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new CompilerException('Dossier impossible à créer : ' . $directory);
        }
    }

    private function slug(string $name): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', $name) ?? '';
        $slug = trim($slug, '-');

        return $slug === '' ? 'program' : $slug;
    }
}
