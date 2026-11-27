<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

/**
 * Filtrage flou des chemins pour la navigation rapide (Ctrl+P).
 *
 * Le score privilégie le nom de fichier, puis les correspondances contiguës ;
 * les caractères en début de segment (`/`, `-`, `_`, `.`) sont bonus.
 */
final class QuickOpenMatcher
{
    /**
     * @param list<string> $paths chemins relatifs au projet
     *
     * @return list<string>
     */
    public static function match(array $paths, string $query, int $limit = 50): array
    {
        $query = trim($query);

        if ($limit <= 0) {
            return [];
        }

        if ($query === '') {
            usort($paths, static function (string $left, string $right): int {
                return substr_count($left, '/') <=> substr_count($right, '/')
                    ?: strlen($left) <=> strlen($right)
                    ?: strcmp($left, $right);
            });

            return array_slice($paths, 0, $limit);
        }

        $needle = strtolower($query);
        $scored = [];

        foreach ($paths as $path) {
            $score = self::score($path, $needle);

            if ($score !== null) {
                $scored[] = [$score, $path];
            }
        }

        usort($scored, static function (array $left, array $right): int {
            return $right[0] <=> $left[0]
                ?: strcmp($left[1], $right[1]);
        });

        return array_map(
            static fn (array $entry): string => $entry[1],
            array_slice($scored, 0, $limit),
        );
    }

    /**
     * Filtrage de libellés de commandes (palette Ctrl+Shift+P).
     *
     * @param list<string> $labels
     *
     * @return list<string>
     */
    public static function matchLabels(array $labels, string $query, int $limit = 50): array
    {
        $query = trim($query);

        if ($limit <= 0) {
            return [];
        }

        if ($query === '') {
            return array_slice($labels, 0, $limit);
        }

        $needle = strtolower($query);
        $scored = [];

        foreach ($labels as $label) {
            $score = self::score($label, $needle);

            if ($score !== null) {
                $scored[] = [$score, $label];
            }
        }

        usort($scored, static function (array $left, array $right): int {
            return $right[0] <=> $left[0]
                ?: strcmp($left[1], $right[1]);
        });

        return array_map(
            static fn (array $entry): string => $entry[1],
            array_slice($scored, 0, $limit),
        );
    }

    private static function score(string $path, string $needle): ?int
    {
        $lower = strtolower($path);
        $basename = strtolower(basename($path));

        if ($basename === $needle) {
            return 3000;
        }

        if (str_starts_with($basename, $needle)) {
            return 2400 - (strlen($basename) - strlen($needle));
        }

        $position = strpos($basename, $needle);

        if ($position !== false) {
            return 2000 - $position;
        }

        $position = strpos($lower, $needle);

        if ($position !== false) {
            return 1600 - $position;
        }

        $score = 0;
        $last = -1;

        foreach (str_split($needle) as $character) {
            $found = strpos($lower, $character, $last + 1);

            if ($found === false) {
                return null;
            }

            if ($found === $last + 1) {
                $score += 12;
            } else {
                $score -= min(6, $found - $last - 1);
            }

            $previous = $found > 0 ? $lower[$found - 1] : '/';

            if (in_array($previous, ['/', '-', '_', '.'], true)) {
                $score += 8;
            }

            $last = $found;
        }

        return 800 + $score - intdiv(strlen($lower), 8);
    }
}
