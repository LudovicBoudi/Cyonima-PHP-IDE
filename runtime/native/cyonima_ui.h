#ifndef CYONIMA_UI_H
#define CYONIMA_UI_H

/*
 * Runtime UI natif minimal pour le sous-ensemble PHP compilé par Cyonima.
 *
 * Les widgets sont référencés par des handles entiers (1..N). Le compilateur
 * génère les wrappers de callbacks et relie les fonctions PHP aux signaux GTK.
 * Cette couche est volontairement procédurale : la façade objet
 * `Cyonima\UI` sera construite au-dessus dans une étape ultérieure.
 */

#include "cyonima_runtime.h"

#include <gtk/gtk.h>

#define CY_UI_MAX_WIDGETS 256

static GtkWidget *cy_ui_widgets[CY_UI_MAX_WIDGETS];
static int cy_ui_widget_count = 0;
static bool cy_ui_initialized = false;
static GMainLoop *cy_ui_loop = NULL;

static inline void cy_ui_error(const char *message)
{
    fprintf(stderr, "cyonima ui: %s\n", message);
    exit(1);
}

static inline void cy_ui_window_destroyed(GtkWidget *widget, gpointer data)
{
    (void) widget;
    (void) data;

    if (cy_ui_loop != NULL && g_main_loop_is_running(cy_ui_loop)) {
        g_main_loop_quit(cy_ui_loop);
    }
}

static inline int cy_ui_register(GtkWidget *widget)
{
    if (widget == NULL) {
        cy_ui_error("widget NULL");
    }

    if (cy_ui_widget_count >= CY_UI_MAX_WIDGETS) {
        cy_ui_error("limite de widgets atteinte");
    }

    cy_ui_widgets[cy_ui_widget_count] = widget;

    return ++cy_ui_widget_count;
}

static inline GtkWidget *cy_ui_widget(cy_int handle)
{
    if (handle <= 0 || handle > cy_ui_widget_count) {
        cy_ui_error("handle de widget invalide");
    }

    return cy_ui_widgets[handle - 1];
}

static inline void cy_ui_init(const char *app_id)
{
    (void) app_id;

    if (cy_ui_initialized) {
        return;
    }

    gtk_init();
    cy_ui_loop = g_main_loop_new(NULL, FALSE);
    cy_ui_initialized = true;

    if (cy_ui_loop == NULL) {
        cy_ui_error("boucle GTK impossible");
    }
}

static inline cy_int cy_ui_window(const char *title, cy_int width, cy_int height)
{
    if (!cy_ui_initialized) {
        cy_ui_error("appelez ui_init() avant ui_window()");
    }

    GtkWidget *window = gtk_window_new();
    gtk_window_set_title(GTK_WINDOW(window), title);
    gtk_window_set_default_size(GTK_WINDOW(window), (int) width, (int) height);
    g_signal_connect(window, "destroy", G_CALLBACK(cy_ui_window_destroyed), NULL);

    return cy_ui_register(window);
}

static inline cy_int cy_ui_box(cy_int vertical, cy_int spacing)
{
    GtkWidget *box = gtk_box_new(
        vertical ? GTK_ORIENTATION_VERTICAL : GTK_ORIENTATION_HORIZONTAL,
        (int) spacing
    );

    return cy_ui_register(box);
}

static inline cy_int cy_ui_label(const char *text)
{
    return cy_ui_register(gtk_label_new(text));
}

static inline cy_int cy_ui_button(const char *text)
{
    return cy_ui_register(gtk_button_new_with_label(text));
}

static inline cy_int cy_ui_entry(const char *placeholder)
{
    GtkWidget *entry = gtk_entry_new();

    if (placeholder != NULL && placeholder[0] != '\0') {
        gtk_entry_set_placeholder_text(GTK_ENTRY(entry), placeholder);
    }

    return cy_ui_register(entry);
}

static inline void cy_ui_append(cy_int parent, cy_int child)
{
    gtk_box_append(GTK_BOX(cy_ui_widget(parent)), cy_ui_widget(child));
}

static inline void cy_ui_set_child(cy_int parent, cy_int child)
{
    gtk_window_set_child(GTK_WINDOW(cy_ui_widget(parent)), cy_ui_widget(child));
}

static inline void cy_ui_present(cy_int window)
{
    gtk_window_present(GTK_WINDOW(cy_ui_widget(window)));
}

static inline void cy_ui_on_click(cy_int button, GCallback callback, gpointer data)
{
    g_signal_connect(cy_ui_widget(button), "clicked", callback, data);
}

static inline void cy_ui_click(cy_int button)
{
    g_signal_emit_by_name(cy_ui_widget(button), "clicked");
}

static inline void cy_ui_on_activate(cy_int entry, GCallback callback, gpointer data)
{
    g_signal_connect(cy_ui_widget(entry), "activate", callback, data);
}

static inline void cy_ui_after(cy_int milliseconds, GSourceFunc callback, gpointer data)
{
    g_timeout_add((guint) milliseconds, callback, data);
}

static inline void cy_ui_call_closure(CyClosure *closure)
{
    ((void (*)(void *)) closure->invoke)(closure);

    if (cy_exception_pending()) {
        fprintf(
            stderr,
            "cyonima: exception non capturée dans un callback UI : %s\n",
            cy_exception_message()
        );
        exit(1);
    }
}

static inline void cy_ui_closure_click(GtkWidget *widget, gpointer data)
{
    (void) widget;
    cy_ui_call_closure((CyClosure *) data);
}

static inline gboolean cy_ui_closure_timer(gpointer data)
{
    cy_ui_call_closure((CyClosure *) data);

    return G_SOURCE_REMOVE;
}

static inline void cy_ui_on_click_closure(cy_int button, CyClosure *closure)
{
    cy_add_root(closure);
    g_signal_connect(
        cy_ui_widget(button),
        "clicked",
        G_CALLBACK(cy_ui_closure_click),
        closure
    );
}

static inline void cy_ui_on_activate_closure(cy_int entry, CyClosure *closure)
{
    cy_add_root(closure);
    g_signal_connect(
        cy_ui_widget(entry),
        "activate",
        G_CALLBACK(cy_ui_closure_click),
        closure
    );
}

static inline void cy_ui_after_closure(cy_int milliseconds, CyClosure *closure)
{
    cy_add_root(closure);
    g_timeout_add((guint) milliseconds, cy_ui_closure_timer, closure);
}

static inline void cy_ui_set_text(cy_int label, const char *text)
{
    gtk_label_set_text(GTK_LABEL(cy_ui_widget(label)), text);
}

static inline const char *cy_ui_entry_text(cy_int entry)
{
    const char *text = gtk_editable_get_text(GTK_EDITABLE(cy_ui_widget(entry)));

    return cy_strdup(text == NULL ? "" : text);
}

static inline void cy_ui_set_visible(cy_int handle, bool visible)
{
    gtk_widget_set_visible(cy_ui_widget(handle), visible);
}

static inline void cy_ui_quit(void)
{
    if (cy_ui_loop != NULL && g_main_loop_is_running(cy_ui_loop)) {
        g_main_loop_quit(cy_ui_loop);
    }
}

static inline cy_int cy_ui_run(void)
{
    if (cy_ui_loop == NULL) {
        cy_ui_error("appelez ui_init() avant ui_run()");
    }

    if (!g_main_loop_is_running(cy_ui_loop)) {
        g_main_loop_run(cy_ui_loop);
    }

    return 0;
}

#endif
