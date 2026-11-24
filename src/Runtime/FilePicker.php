<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

use FFI;
use FFI\CData;
use Throwable;

/**
 * Sélecteur de fichier natif GTK4 (`GtkFileDialog`).
 *
 * Le dialogue est asynchrone : la callback reçoit le chemin choisi. Les objets
 * GTK et la closure C sont conservés jusqu'à la réponse pour éviter un
 * ramasse-miettes prématuré.
 */
final class FilePicker
{
    private const CDEF = <<<'C'
        typedef void (*CyonimaFileCallback)(void*, void*, void*);
        void *gtk_file_dialog_new(void);
        void  gtk_file_dialog_set_title(void *dialog, const char *title);
        void  gtk_file_dialog_set_modal(void *dialog, int modal);
        void  gtk_file_dialog_open(void *dialog, void *parent, void *cancellable, CyonimaFileCallback callback, void *data);
        void *gtk_file_dialog_open_finish(void *dialog, void *result, void *error);
        char *g_file_get_path(void *file);
        void  g_free(void *pointer);
        void  g_object_unref(void *object);
        C;

    private static ?FFI $ffi = null;
    private static int $nextId = 0;

    /** @var array<int, CData> */
    private static array $dialogs = [];

    /** @var array<int, \Closure> */
    private static array $closures = [];

    /** @var array<int, callable(string): void> */
    private static array $callbacks = [];

    private static function ffi(): FFI
    {
        return self::$ffi ??= FFI::cdef(self::CDEF, 'libgtk-4.so.1');
    }

    /**
     * @param CData $parent fenêtre GTK parente (handle)
     * @param callable(string): void $onSelected
     */
    public static function choose(CData $parent, string $title, callable $onSelected): void
    {
        $ffi = self::ffi();
        $dialog = $ffi->gtk_file_dialog_new();
        if ($dialog === null) {
            return;
        }

        $ffi->gtk_file_dialog_set_title($dialog, $title);
        $ffi->gtk_file_dialog_set_modal($dialog, 1);

        $id = ++self::$nextId;
        self::$dialogs[$id] = $dialog;
        self::$callbacks[$id] = $onSelected(...);

        $callback = function ($source, $result) use ($id): void {
            $dialog = self::$dialogs[$id] ?? null;
            $handler = self::$callbacks[$id] ?? null;
            unset(self::$dialogs[$id], self::$callbacks[$id], self::$closures[$id]);

            if ($dialog === null) {
                return;
            }

            $path = null;
            try {
                if ($handler !== null) {
                    $file = self::ffi()->gtk_file_dialog_open_finish($dialog, $result, null);
                    if ($file !== null) {
                        $selected = self::ffi()->g_file_get_path($file);
                        if (is_string($selected) && $selected !== '') {
                            $path = $selected;
                        }
                        self::ffi()->g_object_unref($file);
                    }
                }
            } catch (Throwable $exception) {
                Errors::report($exception, 'file-picker');
            } finally {
                self::ffi()->g_object_unref($dialog);
            }

            if ($path !== null && $handler !== null) {
                try {
                    $handler($path);
                } catch (Throwable $exception) {
                    Errors::report($exception, 'file-picker');
                }
            }
        };

        self::$closures[$id] = $callback;
        $ffi->gtk_file_dialog_open($dialog, $parent, null, $callback, null);
    }
}
