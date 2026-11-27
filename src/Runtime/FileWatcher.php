<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

use FFI;
use FFI\CData;

/**
 * Surveillance de fichiers via Gio (`GFileMonitor`), sans bindings générés.
 *
 * Le callback reste en vie dans l'instance ; les événements arrivent sur la
 * boucle GLib/GTK. Les moniteurs de répertoire ne sont pas récursifs :
 * `WorkspaceWatcher` en crée un par dossier.
 */
final class FileWatcher
{
    private const CDEF = <<<'C'
        void  *g_file_new_for_path(const char *path);
        void  *g_file_monitor_file(void *file, int flags, void *cancellable, void *error);
        void  *g_file_monitor_directory(void *file, int flags, void *cancellable, void *error);
        char  *g_file_get_path(void *file);
        void   g_free(void *memory);
        void   g_object_unref(void *object);
        void   g_file_monitor_cancel(void *monitor);
        void   g_file_monitor_set_rate_limit(void *monitor, int limit_msec);
        unsigned long g_signal_connect_data(
            void *instance, const char *signal,
            void (*callback)(void*, void*, void*, int, void*),
            void *data, void *destroy, int flags);
        C;

    private static ?FFI $gio = null;
    private static ?bool $available = null;

    /** @var \Closure(string, int): void */
    private \Closure $onChange;

    /** @var \Closure */
    private \Closure $handler;

    private function __construct(
        private readonly FFI $ffi,
        private readonly CData $monitor,
        callable $onChange,
    ) {
        $this->onChange = $onChange(...);
    }

    public static function file(string $path, callable $onChange): ?self
    {
        return self::create($path, false, $onChange);
    }

    public static function directory(string $path, callable $onChange): ?self
    {
        return self::create($path, true, $onChange);
    }

    public static function available(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }

        $ffi = self::ffi();

        return self::$available = $ffi !== null;
    }

    public function cancel(): void
    {
        $this->ffi->g_file_monitor_cancel($this->monitor);
    }

    public function __destruct()
    {
        $this->cancel();
        $this->ffi->g_object_unref($this->monitor);
    }

    private static function create(string $path, bool $directory, callable $onChange): ?self
    {
        $ffi = self::ffi();

        if ($ffi === null || (!is_file($path) && !is_dir($path))) {
            return null;
        }

        $file = $ffi->g_file_new_for_path($path);

        if ($file === null) {
            return null;
        }

        $monitor = $directory
            ? $ffi->g_file_monitor_directory($file, 0, null, null)
            : $ffi->g_file_monitor_file($file, 0, null, null);

        $ffi->g_object_unref($file);

        if ($monitor === null) {
            return null;
        }

        $ffi->g_file_monitor_set_rate_limit($monitor, 250);

        $watcher = new self($ffi, $monitor, $onChange);
        $watcher->handler = function (
            mixed $monitorPointer,
            mixed $filePointer,
            mixed $otherPointer,
            int $event,
            mixed $data,
        ) use ($ffi, $watcher): void {
            $path = '';

            if ($filePointer !== null) {
                $pointer = $ffi->g_file_get_path($filePointer);

                if ($pointer !== null) {
                    $path = FFI::string($pointer);
                    $ffi->g_free($pointer);
                }
            }

            ($watcher->onChange)($path, $event);
        };

        $ffi->g_signal_connect_data(
            $monitor,
            'changed',
            $watcher->handler,
            null,
            null,
            0,
        );

        return $watcher;
    }

    private static function ffi(): ?FFI
    {
        if (self::$gio !== null) {
            return self::$gio;
        }

        try {
            return self::$gio = FFI::cdef(self::CDEF, 'libgio-2.0.so.0');
        } catch (\Throwable) {
            return null;
        }
    }
}
