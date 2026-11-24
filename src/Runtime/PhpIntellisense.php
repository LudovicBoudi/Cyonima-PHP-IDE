<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

/**
 * IntelliSense PHP de repli, sans serveur LSP.
 *
 * Propose mots-clés et fonctions courantes, filtrés par préfixe. Utilisé seul
 * quand aucun serveur n'est configuré, et en complément si le serveur ne renvoie
 * rien.
 */
final class PhpIntellisense
{
    /** @var array<string, string> symbole => détail */
    private const SYMBOLS = [
        // Mots-clés
        'abstract' => 'mot-clé',
        'and' => 'mot-clé',
        'array' => 'mot-clé',
        'as' => 'mot-clé',
        'break' => 'mot-clé',
        'callable' => 'mot-clé',
        'case' => 'mot-clé',
        'catch' => 'mot-clé',
        'class' => 'mot-clé',
        'clone' => 'mot-clé',
        'const' => 'mot-clé',
        'continue' => 'mot-clé',
        'declare' => 'mot-clé',
        'default' => 'mot-clé',
        'do' => 'mot-clé',
        'echo' => 'mot-clé',
        'else' => 'mot-clé',
        'elseif' => 'mot-clé',
        'empty' => 'mot-clé',
        'enum' => 'mot-clé',
        'extends' => 'mot-clé',
        'final' => 'mot-clé',
        'finally' => 'mot-clé',
        'fn' => 'mot-clé',
        'for' => 'mot-clé',
        'foreach' => 'mot-clé',
        'function' => 'mot-clé',
        'global' => 'mot-clé',
        'if' => 'mot-clé',
        'implements' => 'mot-clé',
        'include' => 'mot-clé',
        'include_once' => 'mot-clé',
        'instanceof' => 'mot-clé',
        'interface' => 'mot-clé',
        'isset' => 'mot-clé',
        'list' => 'mot-clé',
        'match' => 'mot-clé',
        'namespace' => 'mot-clé',
        'new' => 'mot-clé',
        'null' => 'mot-clé',
        'print' => 'mot-clé',
        'private' => 'mot-clé',
        'protected' => 'mot-clé',
        'public' => 'mot-clé',
        'readonly' => 'mot-clé',
        'require' => 'mot-clé',
        'require_once' => 'mot-clé',
        'return' => 'mot-clé',
        'static' => 'mot-clé',
        'switch' => 'mot-clé',
        'throw' => 'mot-clé',
        'trait' => 'mot-clé',
        'try' => 'mot-clé',
        'unset' => 'mot-clé',
        'use' => 'mot-clé',
        'var' => 'mot-clé',
        'while' => 'mot-clé',
        'yield' => 'mot-clé',

        // Chaînes et tableaux
        'strlen' => 'int strlen(string $string)',
        'str_replace' => 'string str_replace(array|string $search, array|string $replace, string|array $subject)',
        'str_contains' => 'bool str_contains(string $haystack, string $needle)',
        'str_starts_with' => 'bool str_starts_with(string $haystack, string $needle)',
        'str_ends_with' => 'bool str_ends_with(string $haystack, string $needle)',
        'substr' => 'string substr(string $string, int $offset, ?int $length = null)',
        'strtolower' => 'string strtolower(string $string)',
        'strtoupper' => 'string strtoupper(string $string)',
        'ucfirst' => 'string ucfirst(string $string)',
        'trim' => 'string trim(string $string, string $characters = " \\n\\r\\t\\v\\0")',
        'ltrim' => 'string ltrim(string $string, string $characters = " \\n\\r\\t\\v\\0")',
        'rtrim' => 'string rtrim(string $string, string $characters = " \\n\\r\\t\\v\\0")',
        'explode' => 'array explode(string $separator, string $string, int $limit = PHP_INT_MAX)',
        'implode' => 'string implode(string $separator, array $array)',
        'sprintf' => 'string sprintf(string $format, mixed ...$values)',
        'printf' => 'int printf(string $format, mixed ...$values)',
        'number_format' => 'string number_format(float $num, int $decimals = 0)',
        'htmlspecialchars' => 'string htmlspecialchars(string $string)',
        'json_encode' => 'string|false json_encode(mixed $value, int $flags = 0)',
        'json_decode' => 'mixed json_decode(string $json, ?bool $associative = null)',
        'preg_match' => 'int|false preg_match(string $pattern, string $subject, ?array &$matches = null)',
        'preg_match_all' => 'int|false preg_match_all(string $pattern, string $subject, ?array &$matches = null)',
        'preg_replace' => 'string|array|null preg_replace(string|array $pattern, string|array $replacement, string|array $subject)',
        'preg_split' => 'array|false preg_split(string $pattern, string $subject)',
        'base64_encode' => 'string base64_encode(string $string)',
        'base64_decode' => 'string|false base64_decode(string $string, bool $strict = false)',
        'hash' => 'string hash(string $algo, string $data)',
        'password_hash' => 'string password_hash(string $password, string|int|null $algo)',
        'password_verify' => 'bool password_verify(string $password, string $hash)',
        'urlencode' => 'string urlencode(string $string)',
        'rawurlencode' => 'string rawurlencode(string $string)',
        'parse_url' => 'array|int|string|false|null parse_url(string $url, int $component = -1)',
        'http_build_query' => 'string http_build_query(array|object $data)',

        // Tableaux
        'array_map' => 'array array_map(?callable $callback, array $array, array ...$arrays)',
        'array_filter' => 'array array_filter(array $array, ?callable $callback = null, int $mode = 0)',
        'array_reduce' => 'mixed array_reduce(array $array, callable $callback, mixed $initial = null)',
        'array_keys' => 'array array_keys(array $array)',
        'array_values' => 'array array_values(array $array)',
        'array_merge' => 'array array_merge(array ...$arrays)',
        'array_slice' => 'array array_slice(array $array, int $offset, ?int $length = null)',
        'array_search' => 'int|string|false array_search(mixed $needle, array $haystack, bool $strict = false)',
        'array_key_exists' => 'bool array_key_exists(string|int $key, array $array)',
        'in_array' => 'bool in_array(mixed $needle, array $haystack, bool $strict = false)',
        'count' => 'int count(Countable|array $value, int $mode = COUNT_NORMAL)',
        'sort' => 'bool sort(array &$array, int $flags = SORT_REGULAR)',
        'usort' => 'bool usort(array &$array, callable $callback)',
        'range' => 'array range(string|int|float $start, string|int|float $end, int|float $step = 1)',

        // Fichiers et processus
        'file_get_contents' => 'string|false file_get_contents(string $filename)',
        'file_put_contents' => 'int|false file_put_contents(string $filename, mixed $data)',
        'is_file' => 'bool is_file(string $filename)',
        'is_dir' => 'bool is_dir(string $filename)',
        'is_readable' => 'bool is_readable(string $filename)',
        'is_writable' => 'bool is_writable(string $filename)',
        'fopen' => 'resource|false fopen(string $filename, string $mode)',
        'fwrite' => 'int|false fwrite(resource $stream, string $data)',
        'fclose' => 'bool fclose(resource $stream)',
        'fgets' => 'string|false fgets(resource $stream)',
        'scandir' => 'array|false scandir(string $directory)',
        'mkdir' => 'bool mkdir(string $directory, int $permissions = 0777)',
        'unlink' => 'bool unlink(string $filename)',
        'tempnam' => 'string|false tempnam(string $directory, string $prefix)',

        // Temps et divers
        'date' => 'string date(string $format, ?int $timestamp = null)',
        'time' => 'int time()',
        'hrtime' => 'array|int|float|false hrtime(bool $as_number = false)',
        'microtime' => 'string|float microtime(bool $as_float = false)',
        'random_int' => 'int random_int(int $min, int $max)',
        'spl_autoload_register' => 'bool spl_autoload_register(?callable $callback = null)',
        'class_exists' => 'bool class_exists(string $class, bool $autoload = true)',
        'function_exists' => 'bool function_exists(string $function)',
        'method_exists' => 'bool method_exists(object|string $object_or_class, string $method)',
        'property_exists' => 'bool property_exists(object|string $object_or_class, string $property)',
        'get_class' => 'string get_class(object $object = ?)',
        'gettype' => 'string gettype(mixed $value)',
        'var_dump' => 'void var_dump(mixed ...$values)',
        'print_r' => 'string|bool print_r(mixed $value, bool $return = false)',
        'error_log' => 'bool error_log(string $message, int $message_type = 0)',
        'define' => 'bool define(string $constant_name, mixed $value)',
        'defined' => 'bool defined(string $constant_name)',
        'compact' => 'array compact(array|string $var_name, array|string ...$var_names)',
        'extract' => 'int extract(array &$array, int $flags = EXTR_OVERWRITE)',
        'serialize' => 'string serialize(mixed $value)',
        'unserialize' => 'mixed unserialize(string $data)',
        'filter_var' => 'mixed filter_var(mixed $value, int $filter = FILTER_DEFAULT)',
        // SSH2 / réseau Linux
        'ssh2_connect' => 'resource|false ssh2_connect(string $host, int $port = 22)',
        'ssh2_auth_password' => 'bool ssh2_auth_password(resource $session, string $username, string $password)',
        'ssh2_auth_pubkey_file' => 'bool ssh2_auth_pubkey_file(resource $session, string $username, string $publicKey, string $privateKey)',
        'ssh2_exec' => 'resource|false ssh2_exec(resource $session, string $command)',
        'ssh2_sftp' => 'resource|false ssh2_sftp(resource $session)',
        'ssh2_scp_send' => 'bool ssh2_scp_send(resource $session, string $local, string $remote, int $mode = 0644)',
        'stream_socket_client' => 'resource|false stream_socket_client(string $address, int &$errorCode = null, string &$errorMessage = null)',
        'stream_socket_server' => 'resource|false stream_socket_server(string $address, int &$errorCode = null, string &$errorMessage = null)',
    ];

    /**
     * @return list<array{label: string, insertText: string, detail: string, kind: int}>
     */
    public static function suggest(string $prefix, int $limit = 20): array
    {
        $needle = strtolower(ltrim(trim($prefix), '\\$'));

        $starts = [];
        $contains = [];

        foreach (self::SYMBOLS as $name => $detail) {
            $lower = strtolower($name);

            if ($needle === '' || str_starts_with($lower, $needle)) {
                $starts[] = self::item($name, $detail);
            } elseif (str_contains($lower, $needle)) {
                $contains[] = self::item($name, $detail);
            }
        }

        $sort = static fn (array $a, array $b): int => strcmp($a['label'], $b['label']);
        usort($starts, $sort);
        usort($contains, $sort);

        return array_slice(array_merge($starts, $contains), 0, $limit);
    }

    /** @return array{label: string, insertText: string, detail: string, kind: int} */
    private static function item(string $name, string $detail): array
    {
        return [
            'label' => $name,
            'insertText' => $name,
            'detail' => $detail,
            'kind' => str_contains($detail, '(') ? 3 : 14,
        ];
    }
}
