<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

/**
 * Projets récemment ouverts, stockés dans le répertoire de configuration.
 *
 * Le fichier est volontairement tolérant : une entrée corrompue est ignorée
 * plutôt que de bloquer le démarrage de l'IDE.
 */
final class RecentProjects
{
    private const LIMIT = 8;

    private string $file;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?? Config::file('recent-projects.json');
    }

    public function file(): string
    {
        return $this->file;
    }

    /**
     * @return list<array{path: string, name: string, template: string, openedAt: string, exists: bool}>
     */
    public function all(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($this->file), true);
        if (!is_array($data)) {
            return [];
        }

        $entries = [];
        foreach ($data as $entry) {
            if (!is_array($entry) || !is_string($entry['path'] ?? null)) {
                continue;
            }

            $path = $entry['path'];
            $entries[] = [
                'path' => $path,
                'name' => is_string($entry['name'] ?? null) && $entry['name'] !== ''
                    ? $entry['name']
                    : basename($path),
                'template' => is_string($entry['template'] ?? null) ? $entry['template'] : 'folder',
                'openedAt' => is_string($entry['openedAt'] ?? null) ? $entry['openedAt'] : '',
                'exists' => is_dir($path),
            ];
        }

        return $entries;
    }

    public function add(string $path, ?string $name = null, ?string $template = null): void
    {
        $canonical = realpath($path) ?: $path;
        $entries = array_values(array_filter(
            $this->all(),
            static fn (array $entry): bool => $entry['path'] !== $canonical,
        ));

        array_unshift($entries, [
            'path' => $canonical,
            'name' => $name !== null && $name !== '' ? $name : basename($canonical),
            'template' => $template ?? 'folder',
            'openedAt' => gmdate('c'),
            'exists' => is_dir($canonical),
        ]);

        $this->write(array_slice($entries, 0, self::LIMIT));
    }

    public function remove(string $path): void
    {
        $canonical = realpath($path) ?: $path;
        $entries = array_values(array_filter(
            $this->all(),
            static fn (array $entry): bool => $entry['path'] !== $canonical,
        ));

        $this->write($entries);
    }

    public function clear(): void
    {
        if (is_file($this->file)) {
            @unlink($this->file);
        }
    }

    /** @param list<array{path: string, name: string, template: string, openedAt: string, exists: bool}> $entries */
    private function write(array $entries): void
    {
        if ($entries === []) {
            $this->clear();
            return;
        }

        $directory = dirname($this->file);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $payload = json_encode(
            array_map(
                static fn (array $entry): array => [
                    'path' => $entry['path'],
                    'name' => $entry['name'],
                    'template' => $entry['template'],
                    'openedAt' => $entry['openedAt'],
                ],
                $entries,
            ),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($payload !== false) {
            file_put_contents($this->file, $payload . "\n");
        }
    }
}
