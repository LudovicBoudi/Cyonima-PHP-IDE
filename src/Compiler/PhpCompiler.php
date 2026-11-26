<?php

declare(strict_types=1);

namespace Cyonima\Compiler;

use Cyonima\Compiler\Backend\C\PhpCGenerator;
use Cyonima\Compiler\Ast\Program;
use Cyonima\Compiler\Ir\IrBuilder;
use Cyonima\Compiler\Lexer\Lexer;
use Cyonima\Compiler\Lexer\TokenType;
use Cyonima\Compiler\Native\NativeModule;
use Cyonima\Compiler\Native\NativeModuleRegistry;
use Cyonima\Compiler\Packaging\NativePackager;
use Cyonima\Compiler\Parser\Parser;
use Cyonima\Compiler\Semantic\SemanticAnalyzer;
use Cyonima\Compiler\Template\TemplateCompiler;

/**
 * Compile un fichier PHP du sous-ensemble supporté vers C11 puis ELF.
 *
 * Le pipeline est strictement séparé : lexer → parser → analyse sémantique →
 * IR → backend C. Une construction hors périmètre échoue avant toute
 * génération, avec le numéro de ligne.
 */
final class PhpCompiler
{
    /**
     * @param list<string> $moduleNames
     * @param list<string> $sourcePaths
     * @param list<string> $templatePaths
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly array $moduleNames = [],
        private readonly array $sourcePaths = [],
        private readonly array $templatePaths = [],
    ) {
    }

    /**
     * @return array{
     *     ok: bool,
     *     message: string,
     *     cPath: string,
     *     binaryPath: null|string,
     *     linkFlags: string,
     *     packagePath?: string
     * }
     */
    public function compile(
        string $sourcePath,
        ?string $output = null,
        string $emit = 'elf',
        ?string $package = null,
    ): array {
        if ($package !== null) {
            if ($package !== 'appimage' && $package !== 'flatpak') {
                throw new CompilerException('Format de packaging inconnu : ' . $package);
            }

            $emit = 'elf';
        }
        $sourcePath = realpath($sourcePath) ?: $sourcePath;

        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new CompilerException('Fichier PHP introuvable : ' . $sourcePath);
        }

        $sourceFiles = $this->sourceFiles($sourcePath);
        $tokens = [];
        $programs = [];

        foreach ($sourceFiles as $file) {
            $source = file_get_contents($file);

            if ($source === false) {
                throw new CompilerException('Lecture impossible : ' . $file);
            }

            $fileTokens = (new Lexer($source))->tokenize();

            try {
                $fileProgram = (new Parser($fileTokens))->parse();
            } catch (CompilerException $exception) {
                if ($file === $sourcePath) {
                    throw $exception;
                }

                throw new CompilerException(
                    $this->relativePath($file) . ' : ' . $exception->getMessage(),
                );
            }

            $tokens = array_merge($tokens, $fileTokens);
            $programs[$file] = $fileProgram;
        }

        $functions = [];
        $classes = [];
        $interfaces = [];
        $entryProgram = $programs[$sourcePath];

        foreach ($programs as $file => $fileProgram) {
            if ($file === $sourcePath) {
                continue;
            }

            if ($fileProgram->statements !== []) {
                throw new CompilerException(
                    $this->relativePath($file)
                    . ' : un fichier source non-entry ne peut contenir que des déclarations',
                );
            }

            $functions = array_merge($functions, $fileProgram->functions);
            $classes = array_merge($classes, $fileProgram->classes);
            $interfaces = array_merge($interfaces, $fileProgram->interfaces);
        }

        $program = new Program(
            array_merge($functions, $entryProgram->functions),
            array_merge($classes, $entryProgram->classes),
            array_merge($interfaces, $entryProgram->interfaces),
            $entryProgram->statements,
            $entryProgram->line,
        );

        $templates = $this->templateFiles();
        $usesTemplates = $templates !== [];

        foreach ($templates as $templatePath => $className) {
            $templateSource = file_get_contents($templatePath);

            if ($templateSource === false) {
                throw new CompilerException('Lecture impossible : ' . $templatePath);
            }

            try {
                $generated = (new TemplateCompiler($className))->compile($templateSource);
                $templateProgram = (new Parser(
                    (new Lexer($generated))->tokenize(),
                ))->parse();
            } catch (CompilerException $exception) {
                throw new CompilerException(
                    $this->relativePath($templatePath) . ' : ' . $exception->getMessage(),
                );
            }

            $program = new Program(
                array_merge($templateProgram->functions, $program->functions),
                array_merge($templateProgram->classes, $program->classes),
                array_merge($templateProgram->interfaces, $program->interfaces),
                $program->statements,
                $program->line,
            );
        }

        $usesDatabase = $this->usesDatabase($tokens);
        $requestedModules = $this->moduleNames;

        if ($usesDatabase) {
            $requestedModules[] = 'sqlite';
        }

        $registry = new NativeModuleRegistry();
        $modules = $registry->activeForTokens($tokens, $requestedModules);
        $nativeFunctions = $registry->functions($modules);
        $exceptions = (new Parser(
            (new Lexer(ExceptionPrelude::SOURCE))->tokenize(),
        ))->parse();
        $program = new Program(
            array_merge($exceptions->functions, $program->functions),
            array_merge($exceptions->classes, $program->classes),
            array_merge($exceptions->interfaces, $program->interfaces),
            $program->statements,
            $program->line,
        );

        if ($usesDatabase) {
            $database = (new Parser(
                (new Lexer(DatabasePrelude::SOURCE))->tokenize(),
            ))->parse();
            $program = new Program(
                array_merge($database->functions, $program->functions),
                array_merge($database->classes, $program->classes),
                array_merge($database->interfaces, $program->interfaces),
                $program->statements,
                $program->line,
            );
        }

        $usesConsole = $this->usesConsole($tokens);

        if ($usesConsole) {
            $console = (new Parser(
                (new Lexer(ConsolePrelude::SOURCE))->tokenize(),
            ))->parse();
            $program = new Program(
                array_merge($console->functions, $program->functions),
                array_merge($console->classes, $program->classes),
                array_merge($console->interfaces, $program->interfaces),
                $program->statements,
                $program->line,
            );
        }

        if ($this->usesValidation($tokens)) {
            $validation = (new Parser(
                (new Lexer(ValidationPrelude::SOURCE))->tokenize(),
            ))->parse();
            $program = new Program(
                array_merge($validation->functions, $program->functions),
                array_merge($validation->classes, $program->classes),
                array_merge($validation->interfaces, $program->interfaces),
                $program->statements,
                $program->line,
            );
        }

        if ($usesTemplates || $this->usesUiClasses($tokens, $usesConsole)) {
            $prelude = (new Parser((new Lexer(UiPrelude::SOURCE))->tokenize()))->parse();
            $program = new Program(
                array_merge($prelude->functions, $program->functions),
                array_merge($prelude->classes, $program->classes),
                array_merge($prelude->interfaces, $program->interfaces),
                $program->statements,
                $program->line,
            );
        }

        $analysis = (new SemanticAnalyzer($nativeFunctions))->analyze($program);
        $ir = (new IrBuilder($analysis))->build($program);
        $generator = new PhpCGenerator();
        $ui = $generator->usesUi($ir);
        $cSource = $generator->generate($ir, $ui, $analysis->classes, $modules);

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
                'linkFlags' => $this->nativeLinkFlags($modules, false),
            ];
        }

        if ($emit !== 'elf') {
            throw new CompilerException('Sortie inconnue : ' . $emit);
        }

        $linkFlags = $this->nativeLinkFlags($modules);

        $binaryOutput = $package !== null ? null : $output;

        if ($binaryOutput === null || $binaryOutput === '') {
            $binaryOutput = $project . '/dist/' . $slug;
        }

        $this->ensureDirectory(dirname($binaryOutput));
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
            '%s -std=c11 -O2 -o %s %s -I %s %s %s -lm 2>&1',
            escapeshellarg($gcc),
            escapeshellarg($binaryOutput),
            escapeshellarg($cPath),
            escapeshellarg($runtime),
            $gtkFlags,
            $linkFlags,
        );

        exec($command, $lines, $status);

        if ($status !== 0) {
            throw new CompilerException("Échec de la compilation C :\n" . implode("\n", $lines));
        }

        if ($package !== null) {
            $packager = new NativePackager(
                $project,
                dirname(__DIR__, 2),
            );
            $packaged = $packager->build($package, $binaryOutput, $modules, $output);

            return [
                'ok' => true,
                'message' => $packaged['message'],
                'cPath' => $cPath,
                'binaryPath' => $binaryOutput,
                'linkFlags' => $linkFlags,
                'packagePath' => $packaged['path'],
            ];
        }

        return [
            'ok' => true,
            'message' => 'Binaire natif généré : ' . $binaryOutput,
            'cPath' => $cPath,
            'binaryPath' => $binaryOutput,
            'linkFlags' => $linkFlags,
        ];
    }

    /**
     * @param array<string, NativeModule> $modules
     */
    private function nativeLinkFlags(array $modules, bool $validate = true): string
    {
        $flags = [];

        foreach ($modules as $module) {
            $pkgConfig = $module->pkgConfig();

            if ($pkgConfig === null) {
                $flags[] = $module->linkFlags();
                continue;
            }

            if ($validate) {
                exec(
                    'pkg-config --exists ' . escapeshellarg($pkgConfig) . ' 2>/dev/null',
                    $lines,
                    $status,
                );

                if ($status !== 0) {
                    throw new CompilerException(
                        'module natif indisponible : ' . $module->name()
                        . ' (pkg-config ' . $pkgConfig . ')',
                    );
                }
            }

            $moduleFlags = trim((string) shell_exec(
                'pkg-config --libs ' . escapeshellarg($pkgConfig) . ' 2>/dev/null',
            ));

            $flags[] = $moduleFlags !== '' ? $moduleFlags : $module->linkFlags();
        }

        return implode(' ', array_filter($flags));
    }

    /** @return list<string> */
    private function sourceFiles(string $entry): array
    {
        $entry = realpath($entry) ?: $entry;
        $files = [$entry => true];
        $paths = $this->sourcePaths;
        $configPath = rtrim($this->projectRoot, '/') . '/.cyonima/compiler.json';

        if (is_file($configPath)) {
            $config = json_decode((string) file_get_contents($configPath), true);
            $sources = is_array($config) && is_array($config['sources'] ?? null)
                ? $config['sources']
                : [];

            foreach ($sources as $source) {
                if (is_string($source) && trim($source) !== '') {
                    $paths[] = trim($source);
                }
            }
        }

        foreach ($paths as $path) {
            $resolved = str_starts_with($path, '/')
                ? $path
                : rtrim($this->projectRoot, '/') . '/' . $path;

            if (is_dir($resolved)) {
                $directory = realpath($resolved) ?: $resolved;
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(
                        $directory,
                        \FilesystemIterator::SKIP_DOTS,
                    ),
                );
                $found = [];

                foreach ($iterator as $file) {
                    if (!$file->isFile() || $file->getExtension() !== 'php') {
                        continue;
                    }

                    $pathname = $file->getPathname();
                    $parts = explode(DIRECTORY_SEPARATOR, $pathname);

                    if (array_intersect($parts, [
                        'vendor',
                        'dist',
                        'build',
                        'build-dir',
                        '.cyonima',
                        '.git',
                        'node_modules',
                    ]) !== []) {
                        continue;
                    }

                    $found[] = $pathname;
                }

                sort($found);

                foreach ($found as $file) {
                    $files[$file] = true;
                }
                continue;
            }

            if (is_file($resolved)) {
                $files[realpath($resolved) ?: $resolved] = true;
                continue;
            }

            throw new CompilerException('Source déclarée introuvable : ' . $path);
        }

        return array_keys($files);
    }

    /**
     * Découvre les templates `.ui` et associe chaque fichier à sa classe
     * générée dans `Cyonima\Templates`.
     *
     * @return array<string, string>
     */
    private function templateFiles(): array
    {
        $project = rtrim($this->projectRoot, '/');
        $paths = [];

        foreach (['templates', '.cyonima/templates'] as $default) {
            if (is_dir($project . '/' . $default)) {
                $paths[] = $default;
            }
        }

        $configPath = $project . '/.cyonima/compiler.json';

        if (is_file($configPath)) {
            $config = json_decode((string) file_get_contents($configPath), true);
            $templates = is_array($config) && is_array($config['templates'] ?? null)
                ? $config['templates']
                : [];

            foreach ($templates as $template) {
                if (is_string($template) && trim($template) !== '') {
                    $paths[] = trim($template);
                }
            }
        }

        foreach ($this->templatePaths as $path) {
            $paths[] = $path;
        }

        $files = [];

        foreach ($paths as $path) {
            $resolved = str_starts_with($path, '/')
                ? $path
                : $project . '/' . $path;

            if (is_dir($resolved)) {
                $directory = realpath($resolved) ?: $resolved;
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(
                        $directory,
                        \FilesystemIterator::SKIP_DOTS,
                    ),
                );
                $found = [];

                foreach ($iterator as $file) {
                    if (!$file->isFile()
                        || strtolower($file->getExtension()) !== 'ui') {
                        continue;
                    }

                    $pathname = $file->getPathname();
                    $parts = explode(DIRECTORY_SEPARATOR, $pathname);

                    if (array_intersect($parts, [
                        'vendor',
                        'dist',
                        'build',
                        'build-dir',
                        '.git',
                        'node_modules',
                    ]) !== []) {
                        continue;
                    }

                    $found[] = $pathname;
                }

                sort($found);

                foreach ($found as $file) {
                    $files[$file] = true;
                }
                continue;
            }

            if (is_file($resolved)) {
                if (strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) !== 'ui') {
                    throw new CompilerException(
                        'Template invalide (extension .ui attendue) : ' . $path,
                    );
                }

                $files[realpath($resolved) ?: $resolved] = true;
                continue;
            }

            throw new CompilerException('Template déclaré introuvable : ' . $path);
        }

        $templates = [];
        $owners = [];

        foreach (array_keys($files) as $file) {
            $className = $this->templateClassName($file);

            if (isset($owners[$className])) {
                throw new CompilerException(
                    'template : classe ' . $className . ' générée deux fois ('
                    . $this->relativePath($owners[$className]) . ' et '
                    . $this->relativePath($file) . ')',
                );
            }

            $owners[$className] = $file;
            $templates[$file] = $className;
        }

        return $templates;
    }

    private function templateClassName(string $path): string
    {
        $base = pathinfo($path, PATHINFO_FILENAME);
        $parts = preg_split('/[^A-Za-z0-9]+/', $base) ?: [];
        $name = '';

        foreach ($parts as $part) {
            if ($part !== '') {
                $name .= ucfirst($part);
            }
        }

        if ($name === '') {
            throw new CompilerException(
                'template : nom de fichier invalide : ' . $path,
            );
        }

        if (ctype_digit($name[0])) {
            $name = 'Template' . $name;
        }

        return $name;
    }

    private function relativePath(string $path): string
    {
        $root = rtrim($this->projectRoot, '/') . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    private function write(string $path, string $content): void
    {
        if (file_put_contents($path, $content) === false) {
            throw new CompilerException('Écriture impossible : ' . $path);
        }
    }

    /** @param list<\Cyonima\Compiler\Lexer\Token> $tokens */
    private function usesValidation(array $tokens): bool
    {
        $count = count($tokens);

        for ($index = 0; $index < $count - 2; $index++) {
            if ($tokens[$index]->type === TokenType::Identifier
                && $tokens[$index]->literal === 'Cyonima'
                && $tokens[$index + 1]->type === TokenType::Backslash
                && $tokens[$index + 2]->type === TokenType::Identifier
                && $tokens[$index + 2]->literal === 'Validation') {
                return true;
            }
        }

        return false;
    }

    /** @param list<\Cyonima\Compiler\Lexer\Token> $tokens */
    private function usesDatabase(array $tokens): bool
    {
        $count = count($tokens);

        for ($index = 0; $index < $count - 2; $index++) {
            if ($tokens[$index]->type === TokenType::Identifier
                && $tokens[$index]->literal === 'Cyonima'
                && $tokens[$index + 1]->type === TokenType::Backslash
                && $tokens[$index + 2]->type === TokenType::Identifier
                && $tokens[$index + 2]->literal === 'Database') {
                return true;
            }
        }

        return false;
    }

    /** @param list<\Cyonima\Compiler\Lexer\Token> $tokens */
    private function usesConsole(array $tokens): bool
    {
        $count = count($tokens);

        for ($index = 0; $index < $count - 2; $index++) {
            if ($tokens[$index]->type === TokenType::Identifier
                && $tokens[$index]->literal === 'Cyonima'
                && $tokens[$index + 1]->type === TokenType::Backslash
                && $tokens[$index + 2]->type === TokenType::Identifier
                && $tokens[$index + 2]->literal === 'Console') {
                return true;
            }
        }

        return false;
    }

    /** @param list<\Cyonima\Compiler\Lexer\Token> $tokens */
    private function usesUiClasses(array $tokens, bool $console = false): bool
    {
        $classes = [
            'Application',
            'Widget',
            'Window',
            'Box',
            'Label',
            'Button',
            'Entry',
            'CheckButton',
            'ProgressBar',
            'Separator',
        ];
        $count = count($tokens);

        for ($index = 0; $index < $count - 1; $index++) {
            if ($tokens[$index]->type !== TokenType::NewKeyword) {
                continue;
            }

            $next = $index + 1;

            if ($tokens[$next]->type === TokenType::Backslash) {
                $next++;
            }

            if (isset($tokens[$next])
                && $tokens[$next]->type === TokenType::Identifier
                && in_array($tokens[$next]->literal, $classes, true)) {
                if ($console && $tokens[$next]->literal === 'Application') {
                    continue;
                }

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
