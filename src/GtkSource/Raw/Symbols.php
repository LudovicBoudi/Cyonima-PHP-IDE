<?php

declare(strict_types=1);

/**
 * Fichier généré depuis la spec des bindings — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\GtkSource\Raw;

final class Symbols
{
    private const LIBRARY = 'libgtksourceview-5.so.0';

    private const CDEF = <<<'C'
typedef int (*CyonimaSignalHandler)(void*, void*, void*, void*, void*);

int           gtk_init_check(void);
void         *g_object_ref_sink(void *object);
void          g_object_unref(void *object);
unsigned long g_signal_connect_data(void *instance, const char *signal, CyonimaSignalHandler handler, void *data, void *destroy, int flags);
int           g_signal_emit_by_name(void *instance, const char *signal, ...);
void          g_signal_handler_disconnect(void *instance, unsigned long handler_id);

int gtk_source_buffer_get_highlight_syntax(void *self);
int gtk_source_view_get_show_line_numbers(void *self);
void gtk_source_buffer_set_highlight_syntax(void *self, int arg0);
void gtk_source_buffer_set_language(void *self, void* arg0);
void gtk_source_buffer_set_style_scheme(void *self, void* arg0);
void gtk_source_view_set_auto_indent(void *self, int arg0);
void gtk_source_view_set_highlight_current_line(void *self, int arg0);
void gtk_source_view_set_indent_width(void *self, int arg0);
void gtk_source_view_set_insert_spaces_instead_of_tabs(void *self, int arg0);
void gtk_source_view_set_right_margin_position(void *self, int arg0);
void gtk_source_view_set_show_line_numbers(void *self, int arg0);
void gtk_source_view_set_show_right_margin(void *self, int arg0);
void gtk_source_view_set_tab_width(void *self, int arg0);
void* gtk_source_buffer_get_language(void *self);
void* gtk_source_buffer_get_style_scheme(void *self);
void* gtk_source_buffer_new(void* arg0);
void* gtk_source_language_manager_get_default(void);
void* gtk_source_language_manager_get_language(void *self, const char* arg0);
void* gtk_source_style_scheme_manager_get_default(void);
void* gtk_source_style_scheme_manager_get_scheme(void *self, const char* arg0);
void* gtk_source_view_new(void);
void* gtk_source_view_new_with_buffer(void* arg0);
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
