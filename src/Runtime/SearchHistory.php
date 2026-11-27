<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

/**
 * Historique de recherches persistant (JSON, borné).
 */
final class SearchHistory
{
    private const MAX_ENTRIES = 20;

    /** @var list<string> */
    private array $items = [];

    public function __construct(private readonly ?string $file = null)
    {
        $this->load();
    }

    public static function default(): self
    {
        return new self(Config::file('search-history.json'));
    }

    public function add(string $query): void
    {
        $query = trim($query);

        if ($query === '') {
            return;
        }

        $this->items = array_values(array_filter(
            $this->items,
            static fn (string $item): bool => $item !== $query,
        ));
        array_unshift($this->items, $query);
        $this->items = array_slice($this->items, 0, self::MAX_ENTRIES);
        $this->save();
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function at(int $index): ?string
    {
        return $this->items[$index] ?? null;
    }

    public function clear(): void
    {
        $this->items = [];
        $this->save();
    }

    private function load(): void
    {
        if ($this->file === null || !is_file($this->file)) {
            return;
        }

        $content = @file_get_contents($this->file);

        if ($content === false) {
            return;
        }

        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            return;
        }

        $items = [];

        foreach ($decoded as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            }
        }

        $this->items = array_slice(array_values(array_unique($items)), 0, self::MAX_ENTRIES);
    }

    private function save(): void
    {
        if ($this->file === null) {
            return;
        }

        $directory = dirname($this->file);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        @file_put_contents(
            $this->file,
            json_encode($this->items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n",
        );
    }
}
