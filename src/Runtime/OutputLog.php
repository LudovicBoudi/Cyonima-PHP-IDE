<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

/**
 * Journal texte du panneau SORTIE : lignes horodatées, canal optionnel,
 * historique borné.
 */
final class OutputLog
{
    private const MAX_ENTRIES = 2000;

    /** @var list<array{time: string, channel: string, message: string}> */
    private array $entries = [];

    public function append(string $message, string $channel = ''): void
    {
        $lines = preg_split('/\R/', $message) ?: [];

        foreach ($lines as $line) {
            $line = rtrim($line);

            if ($line === '') {
                continue;
            }

            $this->entries[] = [
                'time' => date('H:i:s'),
                'channel' => $channel,
                'message' => $line,
            ];
        }

        if (count($this->entries) > self::MAX_ENTRIES) {
            $this->entries = array_slice($this->entries, -self::MAX_ENTRIES);
        }
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /** @return list<array{time: string, channel: string, message: string}> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function text(): string
    {
        $lines = [];

        foreach ($this->entries as $entry) {
            $prefix = $entry['channel'] === ''
                ? '[' . $entry['time'] . ']'
                : '[' . $entry['time'] . '] [' . $entry['channel'] . ']';

            $lines[] = $prefix . ' ' . $entry['message'];
        }

        return implode("\n", $lines);
    }
}
