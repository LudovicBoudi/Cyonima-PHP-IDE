<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

/**
 * Diagnostics LSP agrégés par fichier.
 *
 * Les lignes et colonnes restent 0-indexées (format LSP) ; l'affichage et
 * l'ouverture de fichier les convertissent en base 1.
 */
final class Diagnostics
{
    /** @var array<string, list<array{path: string, line: int, column: int, severity: int, message: string, source: string}>> */
    private array $files = [];

    public static function localPath(string $uri): string
    {
        if (!str_starts_with($uri, 'file://')) {
            return $uri;
        }

        $path = parse_url($uri, PHP_URL_PATH);

        return is_string($path) ? rawurldecode($path) : $uri;
    }

    /**
     * Remplace les diagnostics d'un fichier. Une liste vide retire le fichier.
     *
     * @param list<array<string, mixed>> $diagnostics
     */
    public function update(string $uri, array $diagnostics): void
    {
        $path = self::localPath($uri);
        $entries = [];

        foreach ($diagnostics as $diagnostic) {
            $message = trim((string) ($diagnostic['message'] ?? ''));

            if ($message === '') {
                continue;
            }

            $range = $diagnostic['range'] ?? [];
            $start = is_array($range) ? ($range['start'] ?? []) : [];
            $severity = (int) ($diagnostic['severity'] ?? 3);

            $entries[] = [
                'path' => $path,
                'line' => max(0, (int) ($start['line'] ?? 0)),
                'column' => max(0, (int) ($start['character'] ?? 0)),
                'severity' => $severity >= 1 && $severity <= 4 ? $severity : 3,
                'message' => str_replace(["\r", "\n"], ' ', $message),
                'source' => (string) ($diagnostic['source'] ?? ''),
            ];
        }

        if ($entries === []) {
            unset($this->files[$path]);
            return;
        }

        $this->files[$path] = $entries;
    }

    public function clear(): void
    {
        $this->files = [];
    }

    /** @return list<array{path: string, line: int, column: int, severity: int, message: string, source: string}> */
    public function all(): array
    {
        $all = [];

        foreach ($this->files as $entries) {
            foreach ($entries as $entry) {
                $all[] = $entry;
            }
        }

        usort($all, static function (array $left, array $right): int {
            return strcmp($left['path'], $right['path'])
                ?: $left['line'] <=> $right['line']
                ?: $left['column'] <=> $right['column']
                ?: strcmp($left['message'], $right['message']);
        });

        return $all;
    }

    public function count(): int
    {
        $count = 0;

        foreach ($this->files as $entries) {
            $count += count($entries);
        }

        return $count;
    }

    public function countSeverity(int $severity): int
    {
        $count = 0;

        foreach ($this->files as $entries) {
            foreach ($entries as $entry) {
                if ($entry['severity'] === $severity) {
                    $count++;
                }
            }
        }

        return $count;
    }

    public function summary(): string
    {
        $errors = $this->countSeverity(1);
        $warnings = $this->countSeverity(2);
        $parts = [];

        if ($errors > 0) {
            $parts[] = $errors . ' erreur' . ($errors > 1 ? 's' : '');
        }

        if ($warnings > 0) {
            $parts[] = $warnings . ' avertissement' . ($warnings > 1 ? 's' : '');
        }

        if ($parts === []) {
            return $this->count() === 0 ? 'Aucun problème' : $this->count() . ' diagnostic(s)';
        }

        return implode(', ', $parts);
    }

    public static function severityLabel(int $severity): string
    {
        return match ($severity) {
            1 => 'Erreur',
            2 => 'Avertissement',
            4 => 'Indication',
            default => 'Information',
        };
    }
}
