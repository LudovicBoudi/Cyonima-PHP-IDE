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
static GtkApplication *cy_ui_application = NULL;

static inline void cy_ui_error(const char *message)
{
    fprintf(stderr, "cyonima ui: %s\n", message);
    exit(1);
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
    if (cy_ui_application != NULL) {
        return;
    }

    cy_ui_application = gtk_application_new(app_id, G_APPLICATION_DEFAULT_FLAGS);

    if (cy_ui_application == NULL) {
        cy_ui_error("application GTK impossible");
    }
}

static inline cy_int cy_ui_window(const char *title, cy_int width, cy_int height)
{
    if (cy_ui_application == NULL) {
        cy_ui_error("appelez ui_init() avant ui_window()");
    }

    GtkWidget *window = gtk_application_window_new(cy_ui_application);
    gtk_window_set_title(GTK_WINDOW(window), title);
    gtk_window_set_default_size(GTK_WINDOW(window), (int) width, (int) height);

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

static inline void cy_ui_on_activate(cy_int entry, GCallback callback, gpointer data)
{
    g_signal_connect(cy_ui_widget(entry), "activate", callback, data);
}

static inline void cy_ui_after(cy_int milliseconds, GSourceFunc callback, gpointer data)
{
    g_timeout_add((guint) milliseconds, callback, data);
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
    if (cy_ui_application != NULL) {
        g_application_quit(G_APPLICATION(cy_ui_application));
    }
}

static inline cy_int cy_ui_run(void)
{
    if (cy_ui_application == NULL) {
        cy_ui_error("appelez ui_init() avant ui_run()");
    }

    int status = g_application_run(G_APPLICATION(cy_ui_application), 0, NULL);
    g_object_unref(cy_ui_application);
    cy_ui_application = NULL;

    return (cy_int) status;
}

#endif
