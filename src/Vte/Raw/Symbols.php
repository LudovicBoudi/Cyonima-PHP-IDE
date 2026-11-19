<?php

declare(strict_types=1);

/**
 * Fichier généré depuis la spec des bindings — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Vte\Raw;

final class Symbols
{
    private const LIBRARY = 'libvte-2.91-gtk4.so.0';

    private const CDEF = <<<'C'
typedef int (*CyonimaSignalHandler)(void*, void*, void*, void*, void*);

int           gtk_init_check(void);
void         *g_object_ref_sink(void *object);
void          g_object_unref(void *object);
unsigned long g_signal_connect_data(void *instance, const char *signal, CyonimaSignalHandler handler, void *data, void *destroy, int flags);
int           g_signal_emit_by_name(void *instance, const char *signal, ...);
void          g_signal_handler_disconnect(void *instance, unsigned long handler_id);

void vte_terminal_set_scrollback_lines(void *self, long arg0);
void* vte_terminal_new(void);
C;

    private static ?\FFI $instance = null;
    private static bool $initialized = false;

    public static function get(): \FFI
    {
        return self::$instance ??= \FFI::cdef(self::CDEF, self::LIBRARY);
    }

    /** Initialise GTK une seule fois, avant toute création de widget. */
    public static function ensureInitialized(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        if (self::get()->gtk_init_check() === 0) {
            throw new \RuntimeException("GTK4 : impossible d'initialiser le display (DISPLAY/Wayland absents ?)");
        }
    }
}
