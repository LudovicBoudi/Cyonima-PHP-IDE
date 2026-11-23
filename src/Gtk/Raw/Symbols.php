<?php

declare(strict_types=1);

/**
 * Fichier généré depuis la spec des bindings — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Gtk\Raw;

final class Symbols
{
    private const LIBRARY = 'libgtk-4.so.1';

    private const CDEF = <<<'C'
typedef int (*CyonimaSignalHandler)(void*, void*, void*, void*, void*);

int           gtk_init_check(void);
void         *g_object_ref_sink(void *object);
void          g_object_unref(void *object);
unsigned long g_signal_connect_data(void *instance, const char *signal, CyonimaSignalHandler handler, void *data, void *destroy, int flags);
int           g_signal_emit_by_name(void *instance, const char *signal, ...);
void          g_signal_handler_disconnect(void *instance, unsigned long handler_id);

const char* gtk_button_get_label(void *self);
const char* gtk_label_get_text(void *self);
const char* gtk_window_get_title(void *self);
int gtk_box_get_homogeneous(void *self);
int gtk_box_get_spacing(void *self);
int gtk_list_box_get_activate_on_single_click(void *self);
int gtk_list_box_row_get_index(void *self);
int gtk_text_buffer_get_char_count(void *self);
int gtk_text_buffer_get_line_count(void *self);
int gtk_widget_get_height(void *self);
int gtk_widget_get_visible(void *self);
int gtk_widget_get_width(void *self);
void gtk_box_append(void *self, void* arg0);
void gtk_box_remove(void *self, void* arg0);
void gtk_box_set_homogeneous(void *self, int arg0);
void gtk_box_set_spacing(void *self, int arg0);
void gtk_button_set_has_frame(void *self, int arg0);
void gtk_button_set_label(void *self, const char* arg0);
void gtk_css_provider_load_from_string(void *self, const char* arg0);
void gtk_label_set_markup(void *self, const char* arg0);
void gtk_label_set_selectable(void *self, int arg0);
void gtk_label_set_text(void *self, const char* arg0);
void gtk_label_set_wrap(void *self, int arg0);
void gtk_label_set_xalign(void *self, float arg0);
void gtk_label_set_yalign(void *self, float arg0);
void gtk_list_box_append(void *self, void* arg0);
void gtk_list_box_remove(void *self, void* arg0);
void gtk_list_box_remove_all(void *self);
void gtk_list_box_row_set_activatable(void *self, int arg0);
void gtk_list_box_row_set_child(void *self, void* arg0);
void gtk_list_box_row_set_selectable(void *self, int arg0);
void gtk_list_box_select_row(void *self, void* arg0);
void gtk_list_box_set_activate_on_single_click(void *self, int arg0);
void gtk_list_box_set_selection_mode(void *self, int arg0);
void gtk_paned_set_end_child(void *self, void* arg0);
void gtk_paned_set_position(void *self, int arg0);
void gtk_paned_set_resize_start_child(void *self, int arg0);
void gtk_paned_set_shrink_start_child(void *self, int arg0);
void gtk_paned_set_start_child(void *self, void* arg0);
void gtk_popover_popdown(void *self);
void gtk_popover_popup(void *self);
void gtk_popover_set_autohide(void *self, int arg0);
void gtk_popover_set_child(void *self, void* arg0);
void gtk_popover_set_has_arrow(void *self, int arg0);
void gtk_popover_set_pointing_to(void *self, void* arg0);
void gtk_scrolled_window_set_child(void *self, void* arg0);
void gtk_style_context_add_provider_for_display(void* arg0, void* arg1, int arg2);
void gtk_style_context_remove_provider_for_display(void* arg0, void* arg1);
void gtk_text_buffer_set_modified(void *self, int arg0);
void gtk_text_buffer_set_text(void *self, const char* arg0, int arg1);
void gtk_text_view_set_editable(void *self, int arg0);
void gtk_text_view_set_monospace(void *self, int arg0);
void gtk_widget_add_controller(void *self, void* arg0);
void gtk_widget_add_css_class(void *self, const char* arg0);
void gtk_widget_remove_css_class(void *self, const char* arg0);
void gtk_widget_set_halign(void *self, int arg0);
void gtk_widget_set_hexpand(void *self, int arg0);
void gtk_widget_set_margin_bottom(void *self, int arg0);
void gtk_widget_set_margin_end(void *self, int arg0);
void gtk_widget_set_margin_start(void *self, int arg0);
void gtk_widget_set_margin_top(void *self, int arg0);
void gtk_widget_set_parent(void *self, void* arg0);
void gtk_widget_set_sensitive(void *self, int arg0);
void gtk_widget_set_size_request(void *self, int arg0, int arg1);
void gtk_widget_set_tooltip_text(void *self, const char* arg0);
void gtk_widget_set_valign(void *self, int arg0);
void gtk_widget_set_vexpand(void *self, int arg0);
void gtk_widget_set_visible(void *self, int arg0);
void gtk_window_close(void *self);
void gtk_window_destroy(void *self);
void gtk_window_maximize(void *self);
void gtk_window_present(void *self);
void gtk_window_set_child(void *self, void* arg0);
void gtk_window_set_decorated(void *self, int arg0);
void gtk_window_set_default_size(void *self, int arg0, int arg1);
void gtk_window_set_modal(void *self, int arg0);
void gtk_window_set_resizable(void *self, int arg0);
void gtk_window_set_title(void *self, const char* arg0);
void* gtk_application_new(const char* arg0, int arg1);
void* gtk_application_window_new(void* arg0);
void* gtk_box_new(int arg0, int arg1);
void* gtk_button_new_from_icon_name(const char* arg0);
void* gtk_button_new_with_label(const char* arg0);
void* gtk_css_provider_new(void);
void* gtk_event_controller_key_new(void);
void* gtk_label_new(const char* arg0);
void* gtk_list_box_get_selected_row(void *self);
void* gtk_list_box_new(void);
void* gtk_list_box_row_get_child(void *self);
void* gtk_list_box_row_new(void);
void* gtk_paned_new(int arg0);
void* gtk_popover_new(void);
void* gtk_scrolled_window_new(void);
void* gtk_text_view_get_buffer(void *self);
void* gtk_widget_get_display(void *self);
void* gtk_widget_get_parent(void *self);
void* gtk_window_get_child(void *self);
void* gtk_window_new(void);
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
