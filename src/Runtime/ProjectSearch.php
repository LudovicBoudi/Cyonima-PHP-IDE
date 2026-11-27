<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

/**
 * Recherche texte simple dans un projet : parcours récursif, fichiers texte,
 * résultats limités. Aucune indexation, aucun daemon.
 */
final class ProjectSearch
{
    private const SKIPPED_DIRECTORIES = [
        '.git',
        'vendor',
        'node_modules',
        'dist',
        'build',
        'build-dir',
    ];

    private const MAX_FILE_SIZE = 1048576;
    private const MAX_LINE_LENGTH = 240;

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
     * @return list<array{path: string, line: int, column: int, text: string}>
     */
    public static function search(string $root, string $query, int $limit = 200): array
    {
        $query = trim($query);

        if ($query === '' || $limit <= 0) {
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
            $relative = str_starts_with($path, $root . DIRECTORY_SEPARATOR)
                ? substr($path, strlen($root) + 1)
                : $path;

            foreach ($lines as $index => $line) {
                $position = stripos($line, $query);

                if ($position === false) {
                    continue;
                }

                $text = trim($line);

                if (strlen($text) > self::MAX_LINE_LENGTH) {
                    $text = substr($text, 0, self::MAX_LINE_LENGTH - 3) . '...';
                }

                $results[] = [
                    'path' => $relative,
                    'line' => $index + 1,
                    'column' => $position + 1,
                    'text' => $text,
                ];

                if (count($results) >= $limit) {
                    return $results;
                }
            }
        }

        return $results;
    }
}
