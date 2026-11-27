<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

/**
 * Recherche et remplacement texte dans un projet : parcours récursif, fichiers
 * texte, résultats limités. Aucune indexation, aucun daemon.
 */
final class ProjectSearch
{
    public const SKIPPED_DIRECTORIES = [
        '.git',
        'vendor',
        'node_modules',
        'dist',
        'build',
        'build-dir',
    ];

    private const MAX_FILE_SIZE = 1048576;
    private const MAX_LINE_LENGTH = 240;
    private const REGEX_DELIMITER = '~';

    /** @return list<string> */
    public static function files(string $root): array
    {
        $root = realpath($root) ?: $root;

        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS,
            ),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            $parts = explode(DIRECTORY_SEPARATOR, $path);

            if (array_intersect($parts, self::SKIPPED_DIRECTORIES) !== []) {
                continue;
            }

            $size = $file->getSize();

            if ($size === false || $size > self::MAX_FILE_SIZE) {
                continue;
            }

            $files[] = $path;
        }

        sort($files);

        return $files;
    }

    /**
     * @param array{caseSensitive?: bool, wholeWord?: bool, regex?: bool} $options
     *
     * @return list<array{path: string, line: int, column: int, text: string}>
     *
     * @throws \InvalidArgumentException quand l'expression régulière est invalide
     */
    public static function search(string $root, string $query, int $limit = 200, array $options = []): array
    {
        $query = trim($query);

        if ($query === '' || $limit <= 0) {
            return [];
        }

        $pattern = self::pattern($query, $options, true);

        if ($pattern === null) {
            return [];
        }

        $root = realpath($root) ?: $root;
        $results = [];

        foreach (self::files($root) as $path) {
            $content = @file_get_contents($path);

            if ($content === false || str_contains($content, "\0")) {
                continue;
            }

            $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
            $relative = self::relativePath($root, $path);

            foreach ($lines as $index => $line) {
                if (preg_match($pattern, $line, $match, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }

                $text = trim($line);

                if (strlen($text) > self::MAX_LINE_LENGTH) {
                    $text = substr($text, 0, self::MAX_LINE_LENGTH - 3) . '...';
                }

                $results[] = [
                    'path' => $relative,
                    'line' => $index + 1,
                    'column' => $match[0][1] + 1,
                    'text' => $text,
                ];

                if (count($results) >= $limit) {
                    return $results;
                }
            }
        }

        return $results;
    }

    /**
     * Remplace toutes les occurrences dans les fichiers du projet.
     *
     * @param array{caseSensitive?: bool, wholeWord?: bool, regex?: bool} $options
     *
     * @return array{
     *     files: list<array{path: string, count: int}>,
     *     changes: int,
     *     replacements: int
     * }
     *
     * @throws \InvalidArgumentException quand l'expression régulière est invalide
     */
    public static function replace(string $root, string $query, string $replacement = '', array $options = []): array
    {
        $query = trim($query);

        if ($query === '') {
            return ['files' => [], 'changes' => 0, 'replacements' => 0];
        }

        $pattern = self::pattern($query, $options, true);
        $root = realpath($root) ?: $root;
        $files = [];
        $replacements = 0;

        foreach (self::files($root) as $path) {
            $content = @file_get_contents($path);

            if ($content === false || str_contains($content, "\0")) {
                continue;
            }

            $count = @preg_match_all($pattern, $content);

            if ($count === false || $count === 0) {
                continue;
            }

            $updated = @preg_replace($pattern, $replacement, $content, -1, $replaced);

            if ($updated === null || $replaced === 0) {
                continue;
            }

            if (@file_put_contents($path, $updated, LOCK_EX) === false) {
                continue;
            }

            $files[] = [
                'path' => self::relativePath($root, $path),
                'count' => $replaced,
            ];
            $replacements += $replaced;
        }

        return [
            'files' => $files,
            'changes' => count($files),
            'replacements' => $replacements,
        ];
    }

    /**
     * Construit le motif PCRE. Retourne null pour une expression invalide en
     * mode recherche ; lève une exception en mode remplacement.
     *
     * @param array{caseSensitive?: bool, wholeWord?: bool, regex?: bool} $options
     */
    private static function pattern(string $query, array $options, bool $throw = false): ?string
    {
        $regex = (bool) ($options['regex'] ?? false);
        $wholeWord = (bool) ($options['wholeWord'] ?? false);
        $caseSensitive = (bool) ($options['caseSensitive'] ?? false);

        $body = $regex
            ? str_replace(self::REGEX_DELIMITER, '\\' . self::REGEX_DELIMITER, $query)
            : preg_quote($query, self::REGEX_DELIMITER);

        if ($wholeWord) {
            $body = '(?<![\w])(?:' . $body . ')(?![\w])';
        }

        $pattern = self::REGEX_DELIMITER . $body . self::REGEX_DELIMITER
            . ($caseSensitive ? '' : 'i');

        if (@preg_match($pattern, '') === false) {
            if ($throw) {
                throw new \InvalidArgumentException(
                    'Expression régulière invalide : ' . $query,
                );
            }

            return null;
        }

        return $pattern;
    }

    private static function relativePath(string $root, string $path): string
    {
        return str_starts_with($path, $root . DIRECTORY_SEPARATOR)
            ? substr($path, strlen($root) + 1)
            : $path;
    }
}
