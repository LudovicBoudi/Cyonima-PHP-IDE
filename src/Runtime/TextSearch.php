<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

/**
 * Recherche et remplacement dans un texte (éditeur), avec options de casse,
 * mot entier et expression régulière.
 */
final class TextSearch
{
    private const DELIMITER = '~';

    /**
     * @param array{caseSensitive?: bool, wholeWord?: bool, regex?: bool} $options
     *
     * @return list<array{start: int, end: int, text: string}>
     *
     * @throws \InvalidArgumentException quand l'expression régulière est invalide
     */
    public static function matches(string $text, string $query, array $options = []): array
    {
        if ($query === '') {
            return [];
        }

        $pattern = self::pattern($query, $options);
        $result = @preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);

        if ($result === false) {
            throw new \InvalidArgumentException('Expression régulière invalide : ' . $query);
        }

        $found = [];

        foreach ($matches[0] ?? [] as $match) {
            [$value, $offset] = $match;

            // Les correspondances vides ne peuvent pas être remplacées sans
            // boucle ; elles sont ignorées.
            if ($value === '') {
                continue;
            }

            $found[] = [
                'start' => $offset,
                'end' => $offset + strlen($value),
                'text' => $value,
            ];
        }

        return $found;
    }

    /**
     * @param array{caseSensitive?: bool, wholeWord?: bool, regex?: bool} $options
     *
     * @throws \InvalidArgumentException quand l'expression régulière est invalide
     */
    public static function replaceAll(
        string $text,
        string $query,
        string $replacement,
        array $options = [],
    ): string {
        if ($query === '') {
            return $text;
        }

        $pattern = self::pattern($query, $options);
        $result = @preg_replace($pattern, $replacement, $text);

        if ($result === null) {
            throw new \InvalidArgumentException('Expression régulière invalide : ' . $query);
        }

        return $result;
    }

    /** @param array{caseSensitive?: bool, wholeWord?: bool, regex?: bool} $options */
    private static function pattern(string $query, array $options): string
    {
        $regex = (bool) ($options['regex'] ?? false);
        $wholeWord = (bool) ($options['wholeWord'] ?? false);
        $caseSensitive = (bool) ($options['caseSensitive'] ?? false);

        $body = $regex
            ? str_replace(self::DELIMITER, '\\' . self::DELIMITER, $query)
            : preg_quote($query, self::DELIMITER);

        if ($wholeWord) {
            $body = '(?<![\w])(?:' . $body . ')(?![\w])';
        }

        $pattern = self::DELIMITER . $body . self::DELIMITER
            . ($caseSensitive ? '' : 'i');

        if (@preg_match($pattern, '') === false) {
            throw new \InvalidArgumentException('Expression régulière invalide : ' . $query);
        }

        return $pattern;
    }
}
