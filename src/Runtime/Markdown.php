<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

/**
 * Rendu Markdown vers balisage Pango (sous-ensemble).
 *
 * Pas de HTML brut : le texte est échappé avant l'application des styles.
 * Titres, gras, italique, code inline, blocs de code, listes, citations,
 * liens et règles horizontales sont pris en charge. Le rendu expose une
 * correspondance ligne source → ligne affichée pour le défilement synchronisé.
 */
final class Markdown
{
    public static function toPango(string $markdown): string
    {
        return self::toPangoMapped($markdown)['pango'];
    }

    /**
     * @return array{pango: string, map: list<int>} map[ligne source] = ligne affichée
     */
    public static function toPangoMapped(string $markdown): array
    {
        $lines = preg_split('/\R/', str_replace("\r\n", "\n", $markdown)) ?: [];
        $output = [];
        $map = [];
        $inCode = false;

        foreach ($lines as $index => $line) {
            $map[$index] = count($output);

            if (str_starts_with(trim($line), '```')) {
                $inCode = !$inCode;
                continue;
            }

            if ($inCode) {
                $escaped = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $output[] = '<tt>' . ($escaped === '' ? ' ' : $escaped) . '</tt>';
                continue;
            }

            if (preg_match('/^\s*$/u', $line) === 1) {
                $output[] = '';
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.*)$/u', $line, $match) === 1) {
                $level = strlen($match[1]);
                $size = match ($level) {
                    1 => 'xx-large',
                    2 => 'x-large',
                    3 => 'large',
                    4 => 'medium',
                    default => 'small',
                };
                $output[] = '<span size="' . $size . '" weight="bold">'
                    . self::inline($match[2])
                    . '</span>';
                continue;
            }

            if (preg_match('/^\s*([-*+])\s+(.*)$/u', $line, $match) === 1) {
                $indent = strlen($line) - strlen(ltrim($line));
                $output[] = str_repeat('  ', intdiv($indent, 2)) . '• ' . self::inline($match[2]);
                continue;
            }

            if (preg_match('/^\s*(\d+)\.\s+(.*)$/u', $line, $match) === 1) {
                $indent = strlen($line) - strlen(ltrim($line));
                $output[] = str_repeat('  ', intdiv($indent, 2)) . $match[1] . '. ' . self::inline($match[2]);
                continue;
            }

            if (preg_match('/^\s*>\s?(.*)$/u', $line, $match) === 1) {
                $output[] = '▏ <i>' . self::inline($match[1]) . '</i>';
                continue;
            }

            if (preg_match('/^\s*([-*_])(?:\s*\1){2,}\s*$/u', $line) === 1) {
                $output[] = '────────';
                continue;
            }

            $output[] = self::inline($line);
        }

        return [
            'pango' => implode("\n", $output),
            'map' => $map,
        ];
    }

    private static function inline(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Les fragments de code sont protégés avant les autres styles.
        $codes = [];
        $escaped = preg_replace_callback('/`([^`]+)`/u', static function (array $match) use (&$codes): string {
            $codes[] = '<tt>' . $match[1] . '</tt>';
            return "\x00" . (count($codes) - 1) . "\x00";
        }, $escaped) ?? $escaped;

        $escaped = preg_replace('/!\[([^\]]*)\]\(([^)]+)\)/u', '[$1 : $2]', $escaped) ?? $escaped;
        $escaped = preg_replace(
            '/\[([^\]]+)\]\(([^)\s]+)\)/u',
            '<a href="$2">$1</a>',
            $escaped,
        ) ?? $escaped;
        $escaped = preg_replace('/\*\*(.+?)\*\*/su', '<b>$1</b>', $escaped) ?? $escaped;
        $escaped = preg_replace('/__(.+?)__/su', '<b>$1</b>', $escaped) ?? $escaped;
        $escaped = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/su', '<i>$1</i>', $escaped) ?? $escaped;
        $escaped = preg_replace('/(?<![\w_])_(?!\s)(.+?)(?<!\s)_(?![\w_])/su', '<i>$1</i>', $escaped) ?? $escaped;
        $escaped = preg_replace('/~~(.+?)~~/su', '<s>$1</s>', $escaped) ?? $escaped;

        foreach ($codes as $index => $code) {
            $escaped = str_replace("\x00" . $index . "\x00", $code, $escaped);
        }

        return $escaped;
    }
}
