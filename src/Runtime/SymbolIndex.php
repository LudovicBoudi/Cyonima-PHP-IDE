<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

/**
 * Index de symboles PHP pour la recherche rapide (Ctrl+T).
 *
 * Parcours par tokenizer : classes, interfaces, traits, énumérations,
 * fonctions et méthodes, avec espace de noms. Pas d'analyse sémantique :
 * les définitions conditionnelles et les fermetures sont ignorées.
 */
final class SymbolIndex
{
    private const MAX_SYMBOLS = 5000;

    private const KIND_LABELS = [
        'class' => 'classe',
        'interface' => 'interface',
        'trait' => 'trait',
        'enum' => 'énumération',
        'function' => 'fonction',
        'method' => 'méthode',
    ];

    /** @return list<array{name: string, shortName: string, kind: string, path: string, line: int}> */
    public static function scan(string $root): array
    {
        $root = realpath($root) ?: $root;
        $symbols = [];

        foreach (ProjectSearch::files($root) as $file) {
            if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }

            $code = @file_get_contents($file);

            if ($code === false || str_contains($code, "\0")) {
                continue;
            }

            $relative = str_starts_with($file, $root . DIRECTORY_SEPARATOR)
                ? substr($file, strlen($root) + 1)
                : $file;

            foreach (self::scanCode($code) as $symbol) {
                $symbol['path'] = $relative;
                $symbols[] = $symbol;

                if (count($symbols) >= self::MAX_SYMBOLS) {
                    break 2;
                }
            }
        }

        usort($symbols, static function (array $left, array $right): int {
            return strcmp($left['name'], $right['name'])
                ?: strcmp($left['path'], $right['path'])
                ?: $left['line'] <=> $right['line'];
        });

        return $symbols;
    }

    public static function kindLabel(string $kind): string
    {
        return self::KIND_LABELS[$kind] ?? $kind;
    }

    /**
     * @return list<array{name: string, shortName: string, kind: string, line: int}>
     */
    private static function scanCode(string $code): array
    {
        if (!\function_exists('token_get_all')) {
            return self::scanCodeFallback($code);
        }

        $tokens = token_get_all($code);
        $count = count($tokens);
        $symbols = [];
        $namespace = '';
        $depth = 0;
        $class = null;
        $classDepth = 0;
        $pendingClass = null;

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if (is_array($token)) {
                [$type, $text, $line] = $token;

                if ($type === T_NAMESPACE) {
                    $namespace = self::readNamespace($tokens, $index + 1);
                    continue;
                }

                if (in_array($type, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                    $name = self::nextSymbolName($tokens, $index + 1);

                    if ($name !== null) {
                        $kind = match ($type) {
                            T_INTERFACE => 'interface',
                            T_TRAIT => 'trait',
                            T_ENUM => 'enum',
                            default => 'class',
                        };
                        $fqn = self::qualify($namespace, $name);
                        $symbols[] = [
                            'name' => $fqn,
                            'shortName' => $name,
                            'kind' => $kind,
                            'line' => $line,
                        ];
                        $pendingClass = $fqn;
                    }

                    continue;
                }

                if ($type === T_FUNCTION) {
                    $name = self::nextSymbolName($tokens, $index + 1, allowAmpersand: true);

                    if ($name !== null) {
                        $kind = $class === null ? 'function' : 'method';
                        $symbols[] = [
                            'name' => $class === null
                                ? self::qualify($namespace, $name)
                                : $class . '::' . $name,
                            'shortName' => $name,
                            'kind' => $kind,
                            'line' => $line,
                        ];
                    }

                    continue;
                }

                if ($type === T_CURLY_OPEN || $type === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                    continue;
                }

                continue;
            }

            if ($token === '{') {
                $depth++;

                if ($pendingClass !== null) {
                    $class = $pendingClass;
                    $classDepth = $depth;
                    $pendingClass = null;
                }

                continue;
            }

            if ($token === '}') {
                if ($class !== null && $depth <= $classDepth) {
                    $class = null;
                }

                $depth = max(0, $depth - 1);
            }
        }

        return $symbols;
    }

    /**
     * Repli sans extension tokenizer : analyse ligne à ligne, suffisante pour
     * les définitions usuelles. Les constructions avancées restent ignorées.
     *
     * @return list<array{name: string, shortName: string, kind: string, line: int}>
     */
    private static function scanCodeFallback(string $code): array
    {
        $lines = preg_split('/\R/', $code) ?: [];
        $symbols = [];
        $namespace = '';
        $class = null;
        $depth = 0;
        $classDepth = null;

        foreach ($lines as $offset => $line) {
            $lineNumber = $offset + 1;
            $clean = preg_replace('~//.*$|#.*$|/\*.*?\*/~', '', $line) ?? $line;

            if (preg_match('/^\s*namespace\s+([^;{]+)/', $clean, $match) === 1) {
                $namespace = trim($match[1], " \t\\");
            }

            if (preg_match('/\b(class|interface|trait|enum)\s+([A-Za-z_]\w*)/', $clean, $match) === 1
                && !str_contains($clean, '::')
            ) {
                $kind = match ($match[1]) {
                    'interface' => 'interface',
                    'trait' => 'trait',
                    'enum' => 'enum',
                    default => 'class',
                };
                $fqn = self::qualify($namespace, $match[2]);
                $symbols[] = [
                    'name' => $fqn,
                    'shortName' => $match[2],
                    'kind' => $kind,
                    'line' => $lineNumber,
                ];
                $class = $fqn;
                $classDepth = null;
            }

            if (preg_match('/\bfunction\s+&?\s*([A-Za-z_]\w*)\s*\(/', $clean, $match) === 1) {
                $symbols[] = [
                    'name' => $class === null
                        ? self::qualify($namespace, $match[1])
                        : $class . '::' . $match[1],
                    'shortName' => $match[1],
                    'kind' => $class === null ? 'function' : 'method',
                    'line' => $lineNumber,
                ];
            }

            $depth += substr_count($clean, '{') - substr_count($clean, '}');

            if ($class !== null && $classDepth === null && str_contains($clean, '{')) {
                $classDepth = $depth;
            }

            if ($class !== null && $classDepth !== null && $depth < $classDepth) {
                $class = null;
                $classDepth = null;
            }
        }

        return $symbols;
    }

    /** @param list<array{0: int, 1: string, 2: int}|string> $tokens */
    private static function readNamespace(array $tokens, int $start): string
    {
        $namespace = '';
        $count = count($tokens);

        for ($index = $start; $index < $count; $index++) {
            $token = $tokens[$index];

            if ($token === ';' || $token === '{') {
                break;
            }

            if (is_array($token) && in_array($token[0], [
                T_STRING,
                T_NAME_QUALIFIED,
                T_NAME_FULLY_QUALIFIED,
                T_NS_SEPARATOR,
            ], true)) {
                $namespace .= $token[1];
            }
        }

        return trim($namespace, '\\');
    }

    /** @param list<array{0: int, 1: string, 2: int}|string> $tokens */
    private static function nextSymbolName(array $tokens, int $start, bool $allowAmpersand = false): ?string
    {
        $count = count($tokens);

        for ($index = $start; $index < $count; $index++) {
            $token = $tokens[$index];

            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                if ($token[0] === T_STRING) {
                    return $token[1];
                }

                return null;
            }

            if ($allowAmpersand && $token === '&') {
                continue;
            }

            return null;
        }

        return null;
    }

    private static function qualify(string $namespace, string $name): string
    {
        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }
}
