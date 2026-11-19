<?php

declare(strict_types=1);

/**
 * Fichier généré depuis la spec des bindings — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Adw\Raw;

final class Symbols
{
    private const LIBRARY = 'libadwaita-1.so.0';

    private const CDEF = <<<'C'
typedef int (*CyonimaSignalHandler)(void*, void*, void*, void*, void*);

int           gtk_init_check(void);
void          adw_init(void);
void         *g_object_ref_sink(void *object);
void          g_object_unref(void *object);
unsigned long g_signal_connect_data(void *instance, const char *signal, CyonimaSignalHandler handler, void *data, void *destroy, int flags);
int           g_signal_emit_by_name(void *instance, const char *signal, ...);
void          g_signal_handler_disconnect(void *instance, unsigned long handler_id);

const char* adw_action_row_get_subtitle(void *self);
int adw_switch_row_get_active(void *self);
void adw_action_row_add_suffix(void *self, void* arg0);
void adw_action_row_set_icon_name(void *self, const char* arg0);
void adw_action_row_set_subtitle(void *self, const char* arg0);
void adw_action_row_set_subtitle_lines(void *self, int arg0);
void adw_application_window_set_content(void *self, void* arg0);
void adw_clamp_set_child(void *self, void* arg0);
void adw_clamp_set_maximum_size(void *self, int arg0);
void adw_clamp_set_tightening_threshold(void *self, int arg0);
void adw_header_bar_pack_end(void *self, void* arg0);
void adw_header_bar_pack_start(void *self, void* arg0);
void adw_header_bar_set_centering_policy(void *self, int arg0);
void adw_header_bar_set_show_back_button(void *self, int arg0);
void adw_header_bar_set_show_end_title_buttons(void *self, int arg0);
void adw_header_bar_set_show_start_title_buttons(void *self, int arg0);
void adw_header_bar_set_title_widget(void *self, void* arg0);
void adw_preferences_group_add(void *self, void* arg0);
void adw_preferences_group_set_description(void *self, const char* arg0);
void adw_preferences_group_set_title(void *self, const char* arg0);
void adw_preferences_page_add(void *self, void* arg0);
void adw_preferences_page_set_description(void *self, const char* arg0);
void adw_preferences_page_set_icon_name(void *self, const char* arg0);
void adw_preferences_page_set_title(void *self, const char* arg0);
void adw_preferences_row_set_title(void *self, const char* arg0);
void adw_preferences_row_set_title_selectable(void *self, int arg0);
void adw_status_page_set_child(void *self, void* arg0);
void adw_status_page_set_description(void *self, const char* arg0);
void adw_status_page_set_icon_name(void *self, const char* arg0);
void adw_status_page_set_title(void *self, const char* arg0);
void adw_switch_row_set_active(void *self, int arg0);
void adw_toast_overlay_add_toast(void *self, void* arg0);
void adw_toast_overlay_set_child(void *self, void* arg0);
void adw_toast_set_button_label(void *self, const char* arg0);
void adw_toast_set_timeout(void *self, int arg0);
void adw_toast_set_title(void *self, const char* arg0);
void adw_toolbar_view_add_bottom_bar(void *self, void* arg0);
void adw_toolbar_view_add_top_bar(void *self, void* arg0);
void adw_toolbar_view_set_content(void *self, void* arg0);
void adw_window_title_set_subtitle(void *self, const char* arg0);
void adw_window_title_set_title(void *self, const char* arg0);
void* adw_action_row_new(void);
void* adw_application_new(const char* arg0, int arg1);
void* adw_application_window_get_content(void *self);
void* adw_application_window_new(void* arg0);
void* adw_clamp_new(void);
void* adw_header_bar_new(void);
void* adw_preferences_group_new(void);
void* adw_preferences_page_new(void);
void* adw_preferences_row_new(void);
void* adw_status_page_new(void);
void* adw_switch_row_new(void);
void* adw_toast_new(const char* arg0);
void* adw_toast_overlay_new(void);
void* adw_toolbar_view_get_content(void *self);
void* adw_toolbar_view_new(void);
void* adw_window_title_new(const char* arg0, const char* arg1);
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

        self::get()->adw_init();
    }
}
