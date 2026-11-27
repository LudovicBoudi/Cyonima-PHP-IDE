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
#include <gio/gio.h>

#define CY_UI_MIN_WIDGETS 32

static GtkWidget **cy_ui_widgets = NULL;
static int cy_ui_widget_count = 0;
static int cy_ui_widget_capacity = 0;
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

    if (cy_ui_widget_count >= cy_ui_widget_capacity) {
        int capacity = cy_ui_widget_capacity == 0
            ? CY_UI_MIN_WIDGETS
            : cy_ui_widget_capacity * 2;
        GtkWidget **widgets = (GtkWidget **) realloc(
            cy_ui_widgets,
            sizeof(GtkWidget *) * (size_t) capacity
        );

        if (widgets == NULL) {
            cy_ui_error("extension du registre de widgets impossible");
        }

        cy_ui_widgets = widgets;
        cy_ui_widget_capacity = capacity;
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

static GObject **cy_ui_models = NULL;
static int cy_ui_model_count = 0;
static int cy_ui_model_capacity = 0;

static inline int cy_ui_register_model(GObject *model)
{
    if (model == NULL) {
        cy_ui_error("modèle NULL");
    }

    if (cy_ui_model_count >= cy_ui_model_capacity) {
        int capacity = cy_ui_model_capacity == 0
            ? 8
            : cy_ui_model_capacity * 2;
        GObject **models = (GObject **) realloc(
            cy_ui_models,
            sizeof(GObject *) * (size_t) capacity
        );

        if (models == NULL) {
            cy_ui_error("extension du registre de modèles impossible");
        }

        cy_ui_models = models;
        cy_ui_model_capacity = capacity;
    }

    cy_ui_models[cy_ui_model_count] = model;

    return ++cy_ui_model_count;
}

static inline GObject *cy_ui_model(cy_int handle)
{
    if (handle <= 0 || handle > cy_ui_model_count) {
        cy_ui_error("handle de modèle invalide");
    }

    return cy_ui_models[handle - 1];
}

static GMenu **cy_ui_menus = NULL;
static int cy_ui_menu_count = 0;
static int cy_ui_menu_capacity = 0;
static GSimpleActionGroup *cy_ui_menu_group = NULL;
static int cy_ui_menu_action_count = 0;

static inline int cy_ui_register_menu(GMenu *menu)
{
    if (menu == NULL) {
        cy_ui_error("menu NULL");
    }

    if (cy_ui_menu_count >= cy_ui_menu_capacity) {
        int capacity = cy_ui_menu_capacity == 0 ? 8 : cy_ui_menu_capacity * 2;
        GMenu **menus = (GMenu **) realloc(
            cy_ui_menus,
            sizeof(GMenu *) * (size_t) capacity
        );

        if (menus == NULL) {
            cy_ui_error("extension du registre de menus impossible");
        }

        cy_ui_menus = menus;
        cy_ui_menu_capacity = capacity;
    }

    cy_ui_menus[cy_ui_menu_count] = menu;

    return ++cy_ui_menu_count;
}

static inline cy_int cy_ui_menu(void)
{
    return cy_ui_register_menu(g_menu_new());
}

static inline GMenu *cy_ui_menu_model(cy_int handle)
{
    if (handle <= 0 || handle > cy_ui_menu_count) {
        cy_ui_error("handle de menu invalide");
    }

    return cy_ui_menus[handle - 1];
}

static inline GSimpleActionGroup *cy_ui_menu_actions(void)
{
    if (cy_ui_menu_group == NULL) {
        cy_ui_menu_group = g_simple_action_group_new();
    }

    return cy_ui_menu_group;
}

typedef struct CyUiRadioGroup CyUiRadioGroup;

typedef struct {
    GAction *action;
    char *value;
} CyUiRadioEntry;

struct CyUiRadioGroup {
    char *name;
    CyUiRadioEntry *entries;
    int count;
    int capacity;
};

typedef struct {
    CyUiRadioGroup *group;
    char *value;
    CyClosure *closure;
} CyUiRadioItem;

static CyUiRadioGroup *cy_ui_radio_groups = NULL;
static int cy_ui_radio_group_count = 0;

static inline CyUiRadioGroup *cy_ui_radio_group(const char *name)
{
    for (int index = 0; index < cy_ui_radio_group_count; index++) {
        if (strcmp(cy_ui_radio_groups[index].name, name) == 0) {
            return &cy_ui_radio_groups[index];
        }
    }

    CyUiRadioGroup *groups = (CyUiRadioGroup *) realloc(
        cy_ui_radio_groups,
        sizeof(CyUiRadioGroup) * (size_t) (cy_ui_radio_group_count + 1)
    );

    if (groups == NULL) {
        cy_ui_error("extension du registre de groupes radio impossible");
    }

    cy_ui_radio_groups = groups;
    CyUiRadioGroup *group = &cy_ui_radio_groups[cy_ui_radio_group_count++];
    memset(group, 0, sizeof(CyUiRadioGroup));
    group->name = g_strdup(name);

    return group;
}

static inline void cy_ui_radio_group_add(
    CyUiRadioGroup *group,
    GAction *action,
    const char *value
) {
    if (group->count >= group->capacity) {
        int capacity = group->capacity == 0 ? 4 : group->capacity * 2;
        CyUiRadioEntry *entries = (CyUiRadioEntry *) realloc(
            group->entries,
            sizeof(CyUiRadioEntry) * (size_t) capacity
        );

        if (entries == NULL) {
            cy_ui_error("extension d’un groupe radio impossible");
        }

        group->entries = entries;
        group->capacity = capacity;
    }

    group->entries[group->count].action = G_ACTION(g_object_ref(action));
    group->entries[group->count].value = g_strdup(value);
    group->count++;
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

static inline cy_int cy_ui_check_button(const char *label)
{
    return cy_ui_register(gtk_check_button_new_with_label(label));
}

static inline bool cy_ui_check_active(cy_int handle)
{
    return gtk_check_button_get_active(GTK_CHECK_BUTTON(cy_ui_widget(handle)));
}

static inline void cy_ui_set_check_active(cy_int handle, bool active)
{
    gtk_check_button_set_active(
        GTK_CHECK_BUTTON(cy_ui_widget(handle)),
        active
    );
}

static inline cy_int cy_ui_progress_bar(void)
{
    return cy_ui_register(gtk_progress_bar_new());
}

static inline cy_float cy_ui_progress_fraction(cy_int handle)
{
    return (cy_float) gtk_progress_bar_get_fraction(
        GTK_PROGRESS_BAR(cy_ui_widget(handle))
    );
}

static inline void cy_ui_set_progress_fraction(cy_int handle, cy_float fraction)
{
    gtk_progress_bar_set_fraction(
        GTK_PROGRESS_BAR(cy_ui_widget(handle)),
        (double) fraction
    );
}

static inline cy_int cy_ui_popover(void)
{
    return cy_ui_register(gtk_popover_new());
}

static inline void cy_ui_popover_set_child(cy_int popover, cy_int child)
{
    gtk_popover_set_child(
        GTK_POPOVER(cy_ui_widget(popover)),
        cy_ui_widget(child)
    );
}

static inline cy_int cy_ui_menu_button(const char *label)
{
    GtkWidget *button = gtk_menu_button_new();

    gtk_menu_button_set_label(GTK_MENU_BUTTON(button), label);

    return cy_ui_register(button);
}

static inline void cy_ui_menu_button_set_label(cy_int button, const char *label)
{
    gtk_menu_button_set_label(GTK_MENU_BUTTON(cy_ui_widget(button)), label);
}

static inline void cy_ui_menu_button_set_popover(cy_int button, cy_int popover)
{
    gtk_menu_button_set_popover(
        GTK_MENU_BUTTON(cy_ui_widget(button)),
        cy_ui_widget(popover)
    );
}

static inline void cy_ui_menu_button_popup(cy_int button)
{
    GtkWidget *widget = cy_ui_widget(button);

    if (gtk_widget_get_root(widget) == NULL) {
        cy_ui_error("menu-button doit être attaché à une fenêtre avant popup()");
    }

    gtk_menu_button_popup(GTK_MENU_BUTTON(widget));
}

static inline void cy_ui_menu_button_popdown(cy_int button)
{
    GtkWidget *widget = cy_ui_widget(button);

    if (gtk_widget_get_root(widget) == NULL) {
        return;
    }

    gtk_menu_button_popdown(GTK_MENU_BUTTON(widget));
}

static inline cy_int cy_ui_separator(cy_int vertical)
{
    return cy_ui_register(
        gtk_separator_new(
            vertical ? GTK_ORIENTATION_VERTICAL : GTK_ORIENTATION_HORIZONTAL
        )
    );
}

static inline void cy_ui_add_class(cy_int handle, const char *name)
{
    if (name == NULL || name[0] == '\0') {
        return;
    }

    gtk_widget_add_css_class(cy_ui_widget(handle), name);
}

static inline void cy_ui_set_tooltip(cy_int handle, const char *text)
{
    gtk_widget_set_tooltip_text(cy_ui_widget(handle), text);
}

static inline void cy_ui_set_sensitive(cy_int handle, bool sensitive)
{
    gtk_widget_set_sensitive(cy_ui_widget(handle), sensitive);
}

static inline void cy_ui_add_css(const char *css)
{
    if (!cy_ui_initialized) {
        cy_ui_error("appelez ui_init() avant ui_add_css()");
    }

    if (css == NULL || css[0] == '\0') {
        return;
    }

    GdkDisplay *display = gdk_display_get_default();

    if (display == NULL) {
        return;
    }

    GtkCssProvider *provider = gtk_css_provider_new();

#if GTK_CHECK_VERSION(4, 12, 0)
    gtk_css_provider_load_from_string(provider, css);
#else
    gtk_css_provider_load_from_data(provider, css, -1);
#endif

    gtk_style_context_add_provider_for_display(
        display,
        GTK_STYLE_PROVIDER(provider),
        GTK_STYLE_PROVIDER_PRIORITY_APPLICATION
    );

    g_object_unref(provider);
}

static inline void cy_ui_set_name(cy_int handle, const char *name)
{
    gtk_widget_set_name(cy_ui_widget(handle), name);
}

static inline void cy_ui_set_size(cy_int handle, cy_int width, cy_int height)
{
    gtk_widget_set_size_request(
        cy_ui_widget(handle),
        (int) width,
        (int) height
    );
}

static inline cy_int cy_ui_image(const char *path)
{
    return cy_ui_register(gtk_image_new_from_file(path));
}

static inline cy_int cy_ui_scrolled(void)
{
    return cy_ui_register(gtk_scrolled_window_new());
}

static inline void cy_ui_scrolled_set_child(cy_int parent, cy_int child)
{
    gtk_scrolled_window_set_child(
        GTK_SCROLLED_WINDOW(cy_ui_widget(parent)),
        cy_ui_widget(child)
    );
}

static inline cy_int cy_ui_string_list(void)
{
    return cy_ui_register_model(G_OBJECT(gtk_string_list_new(NULL)));
}

static inline void cy_ui_string_list_append(cy_int model, const char *value)
{
    gtk_string_list_append(
        GTK_STRING_LIST(cy_ui_model(model)),
        value == NULL ? "" : value
    );
}

static inline cy_int cy_ui_string_list_count(cy_int model)
{
    return (cy_int) g_list_model_get_n_items(G_LIST_MODEL(cy_ui_model(model)));
}

static inline const char *cy_ui_string_list_item(cy_int model, cy_int index)
{
    const char *value = gtk_string_list_get_string(
        GTK_STRING_LIST(cy_ui_model(model)),
        (guint) index
    );

    return cy_strdup(value == NULL ? "" : value);
}

static inline cy_int cy_ui_drop_down(cy_int model)
{
    return cy_ui_register(
        gtk_drop_down_new(G_LIST_MODEL(cy_ui_model(model)), NULL)
    );
}

static inline cy_int cy_ui_drop_down_selected(cy_int handle)
{
    return (cy_int) gtk_drop_down_get_selected(
        GTK_DROP_DOWN(cy_ui_widget(handle))
    );
}

static inline void cy_ui_set_drop_down_selected(cy_int handle, cy_int index)
{
    gtk_drop_down_set_selected(
        GTK_DROP_DOWN(cy_ui_widget(handle)),
        (guint) index
    );
}

static inline const char *cy_ui_drop_down_text(cy_int handle)
{
    GtkDropDown *dropdown = GTK_DROP_DOWN(cy_ui_widget(handle));
    guint index = gtk_drop_down_get_selected(dropdown);

    if (index == GTK_INVALID_LIST_POSITION) {
        return "";
    }

    GObject *item = g_list_model_get_item(
        gtk_drop_down_get_model(dropdown),
        index
    );

    if (item == NULL) {
        return "";
    }

    const char *text = "";
    const char *copy = "";

    if (GTK_IS_STRING_OBJECT(item)) {
        text = gtk_string_object_get_string(GTK_STRING_OBJECT(item));
        copy = cy_strdup(text == NULL ? "" : text);
    }

    g_object_unref(item);

    return copy;
}

static inline cy_int cy_ui_list_box(void)
{
    return cy_ui_register(gtk_list_box_new());
}

static inline void cy_ui_list_box_append(cy_int list, cy_int child)
{
    GtkWidget *widget = cy_ui_widget(child);

    if (!GTK_IS_LIST_BOX_ROW(widget)) {
        GtkWidget *row = gtk_list_box_row_new();
        gtk_list_box_row_set_child(GTK_LIST_BOX_ROW(row), widget);
        widget = row;
    }

    gtk_list_box_append(GTK_LIST_BOX(cy_ui_widget(list)), widget);
}

static inline cy_int cy_ui_list_box_selected(cy_int list)
{
    GtkListBoxRow *row = gtk_list_box_get_selected_row(
        GTK_LIST_BOX(cy_ui_widget(list))
    );

    return row == NULL ? -1 : (cy_int) gtk_list_box_row_get_index(row);
}

static inline void cy_ui_list_box_select(cy_int list, cy_int index)
{
    GtkListBoxRow *row = gtk_list_box_get_row_at_index(
        GTK_LIST_BOX(cy_ui_widget(list)),
        (int) index
    );

    gtk_list_box_select_row(GTK_LIST_BOX(cy_ui_widget(list)), row);
}

static inline void cy_ui_list_box_mode(cy_int list, cy_int mode)
{
    GtkSelectionMode selection = GTK_SELECTION_SINGLE;

    if (mode == 1) {
        selection = GTK_SELECTION_NONE;
    } else if (mode == 2) {
        selection = GTK_SELECTION_MULTIPLE;
    }

    gtk_list_box_set_selection_mode(
        GTK_LIST_BOX(cy_ui_widget(list)),
        selection
    );
}

static inline void cy_ui_list_box_activate(cy_int list, cy_int index)
{
    GtkListBox *box = GTK_LIST_BOX(cy_ui_widget(list));
    GtkListBoxRow *row = gtk_list_box_get_row_at_index(box, (int) index);

    if (row != NULL) {
        g_signal_emit_by_name(box, "row-activated", row);
    }
}

static inline void cy_ui_append(cy_int parent, cy_int child)
{
    GtkWidget *widget = cy_ui_widget(parent);

    if (GTK_IS_LIST_BOX(widget)) {
        cy_ui_list_box_append(parent, child);
        return;
    }

    gtk_box_append(GTK_BOX(widget), cy_ui_widget(child));
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

static inline void cy_ui_closure_check(void)
{
    if (cy_exception_pending()) {
        fprintf(
            stderr,
            "cyonima: exception non capturée dans un callback UI : %s\n",
            cy_exception_message()
        );
        exit(1);
    }
}

static inline void cy_ui_call_closure(CyClosure *closure)
{
    ((void (*)(void *)) closure->invoke)(closure);
    cy_ui_closure_check();
}

static inline void cy_ui_call_closure_bool(CyClosure *closure, bool value)
{
    if (closure->arity >= 1) {
        ((void (*)(void *, bool)) closure->invoke)(closure, value);
    } else {
        ((void (*)(void *)) closure->invoke)(closure);
    }

    cy_ui_closure_check();
}

static inline void cy_ui_call_closure_int(CyClosure *closure, cy_int value)
{
    if (closure->arity >= 1) {
        ((void (*)(void *, cy_int)) closure->invoke)(closure, value);
    } else {
        ((void (*)(void *)) closure->invoke)(closure);
    }

    cy_ui_closure_check();
}

static inline void cy_ui_call_closure_string(CyClosure *closure, const char *value)
{
    if (closure->arity >= 1) {
        ((void (*)(void *, const char *)) closure->invoke)(closure, value);
    } else {
        ((void (*)(void *)) closure->invoke)(closure);
    }

    cy_ui_closure_check();
}

static inline void cy_ui_closure_click(GtkWidget *widget, gpointer data)
{
    (void) widget;
    cy_ui_call_closure((CyClosure *) data);
}

static inline void cy_ui_closure_activate(GtkWidget *widget, gpointer data)
{
    CyClosure *closure = (CyClosure *) data;

    if (closure->arity >= 1) {
        const char *text = gtk_editable_get_text(GTK_EDITABLE(widget));
        cy_ui_call_closure_string(closure, text == NULL ? "" : text);
        return;
    }

    cy_ui_call_closure(closure);
}

static inline void cy_ui_closure_entry_changed(GtkWidget *widget, gpointer data)
{
    CyClosure *closure = (CyClosure *) data;

    if (closure->arity >= 1) {
        const char *text = gtk_editable_get_text(GTK_EDITABLE(widget));
        cy_ui_call_closure_string(closure, text == NULL ? "" : text);
        return;
    }

    cy_ui_call_closure(closure);
}

static inline void cy_ui_closure_toggle(GtkWidget *widget, gpointer data)
{
    CyClosure *closure = (CyClosure *) data;
    cy_ui_call_closure_bool(
        closure,
        gtk_check_button_get_active(GTK_CHECK_BUTTON(widget))
    );
}

static inline void cy_ui_closure_drop_down(
    GObject *object,
    GParamSpec *pspec,
    gpointer data
) {
    (void) pspec;
    CyClosure *closure = (CyClosure *) data;
    GtkDropDown *dropdown = GTK_DROP_DOWN(object);

    if (closure->arity >= 1 && closure->param_kind == 3) {
        guint index = gtk_drop_down_get_selected(dropdown);
        const char *text = "";

        if (index != GTK_INVALID_LIST_POSITION) {
            GObject *item = g_list_model_get_item(
                gtk_drop_down_get_model(dropdown),
                index
            );

            if (item != NULL) {
                if (GTK_IS_STRING_OBJECT(item)) {
                    const char *value = gtk_string_object_get_string(
                        GTK_STRING_OBJECT(item)
                    );
                    text = value == NULL ? "" : value;
                }

                g_object_unref(item);
            }
        }

        cy_ui_call_closure_string(closure, text);
        return;
    }

    cy_ui_call_closure_int(
        closure,
        (cy_int) gtk_drop_down_get_selected(dropdown)
    );
}

static inline void cy_ui_closure_row_activated(
    GtkListBox *box,
    GtkListBoxRow *row,
    gpointer data
) {
    (void) box;
    cy_int index = row == NULL ? -1 : (cy_int) gtk_list_box_row_get_index(row);
    cy_ui_call_closure_int((CyClosure *) data, index);
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
        G_CALLBACK(cy_ui_closure_activate),
        closure
    );
}

static inline void cy_ui_on_entry_changed_closure(cy_int entry, CyClosure *closure)
{
    cy_add_root(closure);
    g_signal_connect(
        cy_ui_widget(entry),
        "changed",
        G_CALLBACK(cy_ui_closure_entry_changed),
        closure
    );
}

static inline void cy_ui_on_toggle_closure(cy_int handle, CyClosure *closure)
{
    cy_add_root(closure);
    g_signal_connect(
        cy_ui_widget(handle),
        "toggled",
        G_CALLBACK(cy_ui_closure_toggle),
        closure
    );
}

static inline void cy_ui_on_drop_down_change_closure(cy_int handle, CyClosure *closure)
{
    cy_add_root(closure);
    g_signal_connect(
        cy_ui_widget(handle),
        "notify::selected",
        G_CALLBACK(cy_ui_closure_drop_down),
        closure
    );
}

static inline void cy_ui_on_row_activated_closure(cy_int list, CyClosure *closure)
{
    cy_add_root(closure);
    g_signal_connect(
        cy_ui_widget(list),
        "row-activated",
        G_CALLBACK(cy_ui_closure_row_activated),
        closure
    );
}

static inline void cy_ui_list_view_setup(
    GtkSignalListItemFactory *factory,
    GtkListItem *item,
    gpointer data
) {
    (void) factory;
    (void) data;

    GtkWidget *label = gtk_label_new("");
    gtk_widget_add_css_class(label, "list-row");
    gtk_list_item_set_child(item, label);
}

static inline void cy_ui_list_view_bind(
    GtkSignalListItemFactory *factory,
    GtkListItem *item,
    gpointer data
) {
    (void) factory;
    (void) data;

    GtkWidget *label = gtk_list_item_get_child(item);
    GObject *object = G_OBJECT(gtk_list_item_get_item(item));

    if (label == NULL || object == NULL || !GTK_IS_STRING_OBJECT(object)) {
        return;
    }

    const char *text = gtk_string_object_get_string(GTK_STRING_OBJECT(object));
    gtk_label_set_text(GTK_LABEL(label), text == NULL ? "" : text);
}

static inline cy_int cy_ui_list_view(cy_int model, cy_int selected)
{
    GtkSingleSelection *selection = gtk_single_selection_new(
        G_LIST_MODEL(cy_ui_model(model))
    );
    gtk_single_selection_set_selected(selection, (guint) selected);

    GtkListItemFactory *factory = gtk_signal_list_item_factory_new();
    g_signal_connect(
        factory,
        "setup",
        G_CALLBACK(cy_ui_list_view_setup),
        NULL
    );
    g_signal_connect(
        factory,
        "bind",
        G_CALLBACK(cy_ui_list_view_bind),
        NULL
    );

    GtkWidget *view = gtk_list_view_new(
        GTK_SELECTION_MODEL(selection),
        factory
    );
    gtk_list_view_set_single_click_activate(GTK_LIST_VIEW(view), TRUE);
    g_object_set_data(G_OBJECT(view), "cyonima-selection", selection);

    return cy_ui_register(view);
}

static inline GtkSingleSelection *cy_ui_list_view_selection(cy_int handle)
{
    return (GtkSingleSelection *) g_object_get_data(
        G_OBJECT(cy_ui_widget(handle)),
        "cyonima-selection"
    );
}

static inline cy_int cy_ui_list_view_selected(cy_int handle)
{
    GtkSingleSelection *selection = cy_ui_list_view_selection(handle);

    if (selection == NULL) {
        return -1;
    }

    guint index = gtk_single_selection_get_selected(selection);

    return index == GTK_INVALID_LIST_POSITION ? -1 : (cy_int) index;
}

static inline void cy_ui_list_view_select(cy_int handle, cy_int index)
{
    GtkSingleSelection *selection = cy_ui_list_view_selection(handle);

    if (selection != NULL) {
        gtk_single_selection_set_selected(selection, (guint) index);
    }
}

static inline void cy_ui_list_view_set_single_click(cy_int handle, bool single)
{
    gtk_list_view_set_single_click_activate(
        GTK_LIST_VIEW(cy_ui_widget(handle)),
        single
    );
}

static inline void cy_ui_list_view_activated(
    GtkListView *view,
    guint position,
    gpointer data
) {
    (void) view;
    cy_ui_call_closure_int((CyClosure *) data, (cy_int) position);
}

static inline void cy_ui_on_list_view_activated_closure(
    cy_int view,
    CyClosure *closure
) {
    cy_add_root(closure);
    g_signal_connect(
        cy_ui_widget(view),
        "activate",
        G_CALLBACK(cy_ui_list_view_activated),
        closure
    );
}

static inline void cy_ui_list_view_activate(cy_int view, cy_int index)
{
    g_signal_emit_by_name(
        cy_ui_widget(view),
        "activate",
        (guint) index
    );
}

static inline void cy_ui_string_list_changed(
    GListModel *model,
    guint position,
    guint removed,
    guint added,
    gpointer data
) {
    (void) model;
    (void) removed;
    (void) added;
    cy_ui_call_closure_int((CyClosure *) data, (cy_int) position);
}

static inline void cy_ui_string_list_on_changed(
    cy_int model,
    CyClosure *closure
) {
    cy_add_root(closure);
    g_signal_connect(
        cy_ui_model(model),
        "items-changed",
        G_CALLBACK(cy_ui_string_list_changed),
        closure
    );
}

static inline void cy_ui_menu_activated(
    GSimpleAction *action,
    GVariant *parameter,
    gpointer data
) {
    (void) action;
    (void) parameter;
    cy_ui_call_closure((CyClosure *) data);
}

static inline cy_int cy_ui_menu_bar(cy_int root)
{
    GtkWidget *bar = gtk_popover_menu_bar_new_from_model(
        G_MENU_MODEL(cy_ui_menu_model(root))
    );

    gtk_widget_insert_action_group(
        bar,
        "cymenu",
        G_ACTION_GROUP(cy_ui_menu_actions())
    );

    return cy_ui_register(bar);
}

static inline void cy_ui_menu_action_name(
    int index,
    char *name,
    size_t nameSize,
    char *action,
    size_t actionSize
) {
    snprintf(name, nameSize, "item%d", index);
    snprintf(action, actionSize, "cymenu.%s", name);
}

static inline void cy_ui_menu_item_attributes(
    GMenuItem *item,
    const char *accelerator,
    const char *icon
) {
    if (accelerator != NULL && accelerator[0] != '\0') {
        g_menu_item_set_attribute(item, "accel", "s", accelerator);
    }

    if (icon != NULL && icon[0] != '\0') {
        GError *error = NULL;
        GIcon *gicon = g_icon_new_for_string(icon, &error);

        if (gicon != NULL) {
            g_menu_item_set_icon(item, gicon);
            g_object_unref(gicon);
        }

        if (error != NULL) {
            g_error_free(error);
        }
    }
}

static inline cy_int cy_ui_menu_add_item_ex(
    cy_int menu,
    const char *label,
    const char *accelerator,
    const char *icon,
    CyClosure *closure
) {
    GSimpleActionGroup *group = cy_ui_menu_actions();
    int index = cy_ui_menu_action_count++;
    char name[32];
    char action[64];

    cy_ui_menu_action_name(index, name, sizeof(name), action, sizeof(action));

    GSimpleAction *simple = g_simple_action_new(name, NULL);
    cy_add_root(closure);
    g_signal_connect(
        simple,
        "activate",
        G_CALLBACK(cy_ui_menu_activated),
        closure
    );
    g_action_map_add_action(G_ACTION_MAP(group), G_ACTION(simple));
    g_object_unref(simple);

    GMenuItem *item = g_menu_item_new(label, action);
    cy_ui_menu_item_attributes(item, accelerator, icon);
    g_menu_append_item(cy_ui_menu_model(menu), item);

    return (cy_int) index;
}

static inline cy_int cy_ui_menu_add_item(
    cy_int menu,
    const char *label,
    CyClosure *closure
) {
    return cy_ui_menu_add_item_ex(menu, label, NULL, NULL, closure);
}

static inline void cy_ui_menu_check_changed(
    GSimpleAction *action,
    GVariant *value,
    gpointer data
) {
    g_simple_action_set_state(action, value);
    cy_ui_call_closure_bool((CyClosure *) data, g_variant_get_boolean(value));
}

static inline cy_int cy_ui_menu_add_check_item(
    cy_int menu,
    const char *label,
    bool checked,
    const char *accelerator,
    const char *icon,
    CyClosure *closure
) {
    GSimpleActionGroup *group = cy_ui_menu_actions();
    int index = cy_ui_menu_action_count++;
    char name[32];
    char action[64];

    cy_ui_menu_action_name(index, name, sizeof(name), action, sizeof(action));

    GSimpleAction *simple = g_simple_action_new_stateful(
        name,
        G_VARIANT_TYPE_BOOLEAN,
        g_variant_new_boolean(checked)
    );
    cy_add_root(closure);
    g_signal_connect(
        simple,
        "change-state",
        G_CALLBACK(cy_ui_menu_check_changed),
        closure
    );
    g_action_map_add_action(G_ACTION_MAP(group), G_ACTION(simple));
    g_object_unref(simple);

    GMenuItem *item = g_menu_item_new(label, action);
    g_menu_item_set_attribute_value(
        item,
        "toggle",
        g_variant_new_boolean(TRUE)
    );
    cy_ui_menu_item_attributes(item, accelerator, icon);
    g_menu_append_item(cy_ui_menu_model(menu), item);

    return (cy_int) index;
}

static inline void cy_ui_menu_radio_apply(
    CyUiRadioItem *item,
    GAction *self
) {
    g_simple_action_set_state(
        G_SIMPLE_ACTION(self),
        g_variant_new_string(item->value)
    );

    for (int index = 0; index < item->group->count; index++) {
        GAction *other = item->group->entries[index].action;

        if (other != self) {
            g_simple_action_set_state(
                G_SIMPLE_ACTION(other),
                g_variant_new_string("")
            );
        }
    }

    CyClosure *closure = item->closure;

    if (closure->arity >= 1 && closure->param_kind == 3) {
        cy_ui_call_closure_string(closure, item->value);
    } else {
        cy_ui_call_closure(closure);
    }
}

static inline void cy_ui_menu_radio_changed(
    GSimpleAction *action,
    GVariant *value,
    gpointer data
) {
    (void) value;
    cy_ui_menu_radio_apply((CyUiRadioItem *) data, G_ACTION(action));
}

static inline cy_int cy_ui_menu_add_radio_item(
    cy_int menu,
    const char *groupName,
    const char *label,
    const char *value,
    bool checked,
    const char *accelerator,
    const char *icon,
    CyClosure *closure
) {
    CyUiRadioGroup *group = cy_ui_radio_group(groupName);
    GSimpleActionGroup *actions = cy_ui_menu_actions();
    int index = cy_ui_menu_action_count++;
    char name[32];
    char action[64];

    cy_ui_menu_action_name(index, name, sizeof(name), action, sizeof(action));

    GSimpleAction *simple = g_simple_action_new_stateful(
        name,
        G_VARIANT_TYPE_STRING,
        g_variant_new_string(checked ? value : "")
    );

    CyUiRadioItem *item = (CyUiRadioItem *) malloc(sizeof(CyUiRadioItem));

    if (item == NULL) {
        cy_ui_error("allocation d’un item radio impossible");
    }

    item->group = group;
    item->value = g_strdup(value);
    item->closure = closure;

    if (checked) {
        for (int existing = 0; existing < group->count; existing++) {
            g_simple_action_set_state(
                G_SIMPLE_ACTION(group->entries[existing].action),
                g_variant_new_string("")
            );
        }
    }

    cy_add_root(closure);
    g_object_set_data(G_OBJECT(simple), "cyonima-radio", item);
    g_signal_connect(
        simple,
        "change-state",
        G_CALLBACK(cy_ui_menu_radio_changed),
        item
    );
    g_action_map_add_action(G_ACTION_MAP(actions), G_ACTION(simple));
    cy_ui_radio_group_add(group, G_ACTION(simple), value);
    g_object_unref(simple);

    GMenuItem *menuItem = g_menu_item_new(label, NULL);
    g_menu_item_set_action_and_target_value(
        menuItem,
        action,
        g_variant_new_string(value)
    );
    g_menu_item_set_attribute_value(
        menuItem,
        "radio",
        g_variant_new_boolean(TRUE)
    );
    cy_ui_menu_item_attributes(menuItem, accelerator, icon);
    g_menu_append_item(cy_ui_menu_model(menu), menuItem);

    return (cy_int) index;
}

static inline void cy_ui_menu_select_radio(cy_int index)
{
    char name[32];
    char action[64];

    cy_ui_menu_action_name(index, name, sizeof(name), action, sizeof(action));

    GAction *target = g_action_map_lookup_action(
        G_ACTION_MAP(cy_ui_menu_actions()),
        name
    );

    if (target == NULL) {
        return;
    }

    CyUiRadioItem *item = g_object_get_data(
        G_OBJECT(target),
        "cyonima-radio"
    );

    if (item != NULL) {
        cy_ui_menu_radio_apply(item, target);
    }
}

static inline void cy_ui_menu_set_enabled(cy_int index, bool enabled)
{
    char name[32];
    char action[64];

    cy_ui_menu_action_name(index, name, sizeof(name), action, sizeof(action));

    GAction *target = g_action_map_lookup_action(
        G_ACTION_MAP(cy_ui_menu_actions()),
        name
    );

    if (target != NULL) {
        g_simple_action_set_enabled(G_SIMPLE_ACTION(target), enabled);
    }
}

static inline bool cy_ui_menu_is_enabled(cy_int index)
{
    char name[32];
    char action[64];

    cy_ui_menu_action_name(index, name, sizeof(name), action, sizeof(action));

    GAction *target = g_action_map_lookup_action(
        G_ACTION_MAP(cy_ui_menu_actions()),
        name
    );

    return target != NULL && g_action_get_enabled(target);
}

static inline void cy_ui_menu_set_check(cy_int index, bool checked)
{
    char name[32];
    char action[64];

    cy_ui_menu_action_name(index, name, sizeof(name), action, sizeof(action));

    GAction *target = g_action_map_lookup_action(
        G_ACTION_MAP(cy_ui_menu_actions()),
        name
    );

    if (target != NULL) {
        g_action_change_state(target, g_variant_new_boolean(checked));
    }
}

static inline void cy_ui_menu_activate(cy_int index)
{
    char name[32];

    snprintf(name, sizeof(name), "item%d", (int) index);
    g_action_group_activate_action(
        G_ACTION_GROUP(cy_ui_menu_actions()),
        name,
        NULL
    );
}

static inline void cy_ui_menu_add_separator(cy_int menu)
{
    g_menu_append(cy_ui_menu_model(menu), NULL, NULL);
}

static inline void cy_ui_menu_add_submenu(
    cy_int parent,
    const char *label,
    cy_int child
) {
    g_menu_append_submenu(
        cy_ui_menu_model(parent),
        label,
        G_MENU_MODEL(cy_ui_menu_model(child))
    );
}

static inline gboolean cy_ui_shortcut_activated(
    GtkWidget *widget,
    GVariant *arguments,
    gpointer data
) {
    (void) widget;
    (void) arguments;
    cy_ui_call_closure((CyClosure *) data);

    return TRUE;
}

static inline void cy_ui_shortcut(
    cy_int window,
    const char *accelerator,
    CyClosure *closure
) {
    GtkShortcutTrigger *trigger = gtk_shortcut_trigger_parse_string(accelerator);

    if (trigger == NULL) {
        cy_ui_error("raccourci clavier invalide");
    }

    cy_add_root(closure);

    GtkShortcutAction *action = gtk_callback_action_new(
        cy_ui_shortcut_activated,
        closure,
        NULL
    );
    GtkShortcut *shortcut = gtk_shortcut_new(trigger, action);
    GtkEventController *controller = gtk_shortcut_controller_new();

    gtk_shortcut_controller_add_shortcut(
        GTK_SHORTCUT_CONTROLLER(controller),
        shortcut
    );
    gtk_widget_add_controller(cy_ui_widget(window), controller);
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

static inline const char *cy_ui_label_text(cy_int label)
{
    const char *text = gtk_label_get_text(GTK_LABEL(cy_ui_widget(label)));

    return cy_strdup(text == NULL ? "" : text);
}

static inline const char *cy_ui_entry_text(cy_int entry)
{
    const char *text = gtk_editable_get_text(GTK_EDITABLE(cy_ui_widget(entry)));

    return cy_strdup(text == NULL ? "" : text);
}

static inline void cy_ui_entry_set_text(cy_int entry, const char *text)
{
    gtk_editable_set_text(
        GTK_EDITABLE(cy_ui_widget(entry)),
        text == NULL ? "" : text
    );
}

static inline void cy_ui_activate_entry(cy_int entry)
{
    g_signal_emit_by_name(cy_ui_widget(entry), "activate");
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
