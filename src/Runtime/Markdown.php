<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

/**
 * Rendu Markdown vers balisage Pango (sous-ensemble).
 *
 * Pas de HTML brut : le texte est échappé avant l'application des styles.
 * Titres, gras, italique, code inline, blocs de code, listes, citations,
 * liens et règles horizontales sont pris en charge.
 */
final class Markdown
{
    public static function toPango(string $markdown): string
    {
        $lines = preg_split('/\R/', str_replace("\r\n", "\n", $markdown)) ?: [];
        $output = [];
        $inCode = false;
        $code = [];

        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '```')) {
                if ($inCode) {
                    $output[] = self::codeBlock($code);
                    $code = [];
                    $inCode = false;
                } else {
                    $inCode = true;
                }

                continue;
            }

            if ($inCode) {
                $code[] = $line;
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

        if ($inCode) {
            $output[] = self::codeBlock($code);
        }

        return implode("\n", $output);
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

    /** @param list<string> $lines */
    private static function codeBlock(array $lines): string
    {
        $escaped = array_map(
            static fn (string $line): string => htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $lines,
        );

        return '<tt>' . implode("\n", $escaped) . '</tt>';
    }
}
