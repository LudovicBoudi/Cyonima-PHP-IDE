<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

use FFI;
use FFI\CData;

/**
 * Pont minimal vers VTE : spawn d'un PTY et écriture dans le terminal.
 *
 * Le typelib expose `spawn_async` avec des tableaux `char**` et deux callbacks,
 * hors du périmètre du générateur. Ce pont sera supprimé quand le générateur
 * gérera les tableaux et les callbacks typés (M5).
 */
final class Vte
{
    private const CDEF = <<<'C'
        typedef struct { unsigned int domain; int code; char *message; } GError;
        typedef void (*VteSpawnCallback)(void *terminal, int pid, void *error, void *user_data);

        void *vte_terminal_new(void);
        void  vte_terminal_spawn_async(
            void *terminal, int pty_flags, const char *working_directory,
            char **argv, char **envv, int spawn_flags,
            void *child_setup, void *child_setup_data, void *child_setup_data_destroy,
            int timeout, void *cancellable, VteSpawnCallback callback, void *user_data);
        void  vte_terminal_feed_child(void *terminal, const char *data, long length);
        C;

    private static ?FFI $ffi = null;

    /** @var array<int, array{argv: list<CData>, callback: \Closure}> */
    private static array $pending = [];

    private static int $nextId = 0;

    private static function ffi(): FFI
    {
        return self::$ffi ??= FFI::cdef(self::CDEF, 'libvte-2.91-gtk4.so.0');
    }

    /**
     * Lance un processus dans le PTY du terminal.
     *
     * @param list<string> $command
     * @param null|callable(int $pid, ?string $error): void $onSpawn
     */
    public static function spawn(
        CData $terminal,
        array $command,
        ?string $workingDirectory = null,
        ?callable $onSpawn = null,
    ): void {
        $ffi = self::ffi();
        $id = ++self::$nextId;

        $argv = $ffi->new('char*[' . (count($command) + 1) . ']', false);
        $buffers = [];

        foreach ($command as $index => $argument) {
            $buffer = $ffi->new('char[' . (strlen($argument) + 1) . ']', false);
            FFI::memcpy($buffer, $argument, strlen($argument));
            $buffer[strlen($argument)] = "\0";

            $buffers[] = $buffer;
            $argv[$index] = $ffi->cast('char*', $buffer);
        }
        $argv[count($command)] = null;

        $callback = function (mixed $instance, int $pid, mixed $error, mixed $data) use ($id, $onSpawn): void {
            unset(self::$pending[$id]);

            if ($onSpawn === null) {
                return;
            }

            $message = null;
            if ($error !== null) {
                $gerror = self::ffi()->cast('GError*', $error);
                $message = FFI::string($gerror->message);
            }

            $onSpawn($pid, $message);
        };

        self::$pending[$id] = ['argv' => $buffers, 'callback' => $callback];

        $ffi->vte_terminal_spawn_async(
            $terminal,
            0,               // VTE_PTY_DEFAULT
            $workingDirectory,
            $argv,
            null,            // hérite de l'environnement
            0,               // G_SPAWN_DEFAULT
            null,
            null,
            null,
            -1,              // pas de timeout
            null,
            $callback,
            null,
        );
    }

    /** Envoie des octets au processus attaché au terminal. */
    public static function feed(CData $terminal, string $data): void
    {
        self::ffi()->vte_terminal_feed_child($terminal, $data, strlen($data));
    }
}
