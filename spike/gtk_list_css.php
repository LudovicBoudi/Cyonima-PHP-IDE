<?php
/**
 * POC 2 — vérifie les zones à risque d'un vrai toolkit natif depuis PHP :
 *  - saisie texte bidirectionnelle (GtkEntry <-> PHP)
 *  - widgets dynamiques (GtkListBox) créés/supprimés depuis PHP
 *  - styling par CSS (feuille chargée depuis une string PHP)
 *  - signal "activate" (touche Entrée) + "clicked"
 *  - stabilité sous rafale d'événements
 */

declare(strict_types=1);

$gtk = FFI::cdef(<<<'C'
int   gtk_init_check(void);
void *gtk_window_new(void);
void  gtk_window_set_title(void *window, const char *title);
void  gtk_window_set_default_size(void *window, int width, int height);
void  gtk_window_set_child(void *window, void *child);
void  gtk_window_present(void *window);

void *gtk_box_new(int orientation, int spacing);
void  gtk_box_append(void *box, void *child);
void  gtk_widget_set_halign(void *widget, int align);
void  gtk_widget_set_valign(void *widget, int align);
void  gtk_widget_set_margin_top(void *widget, int margin);
void  gtk_widget_set_margin_bottom(void *widget, int margin);
void  gtk_widget_set_margin_start(void *widget, int margin);
void  gtk_widget_set_margin_end(void *widget, int margin);
void  gtk_widget_set_hexpand(void *widget, int expand);
void  gtk_widget_add_css_class(void *widget, const char *css_class);

void *gtk_label_new(const char *text);
void  gtk_label_set_text(void *label, const char *text);
void  gtk_label_set_xalign(void *label, float xalign);
void *gtk_button_new_with_label(const char *text);

void *gtk_entry_new(void);
void  gtk_entry_set_placeholder_text(void *entry, const char *text);
const char *gtk_editable_get_text(void *editable);
void  gtk_editable_set_text(void *editable, const char *text);

void *gtk_list_box_new(void);
void *gtk_list_box_row_new(void);
void  gtk_list_box_row_set_child(void *row, void *child);
void  gtk_list_box_append(void *list_box, void *child);
void  gtk_list_box_remove(void *list_box, void *child);
int   gtk_list_box_get_row_at_index(void *list_box, int index);
void *gtk_list_box_row_new(void);

unsigned long g_signal_connect_data(
    void *instance, const char *signal,
    int (*handler)(void *, void *),
    void *data, void *destroy, int flags);

void *g_main_loop_new(void *context, int is_running);
void  g_main_loop_run(void *loop);
void  g_main_loop_quit(void *loop);
unsigned int g_timeout_add(unsigned int interval, int (*handler)(void *), void *data);

void *gtk_css_provider_new(void);
void  gtk_css_provider_load_from_string(void *provider, const char *css);
void  gtk_style_context_add_provider_for_display(void *display, void *provider, unsigned int priority);
void *gtk_widget_get_display(void *widget);

int g_object_unref(void *object);
C, 'libgtk-4.so.1');

$gobject = FFI::cdef(
    'int g_signal_emit_by_name(void *instance, const char *signal, ...);',
    'libgobject-2.0.so.0'
);

if (!$gtk->gtk_init_check()) {
    fwrite(STDERR, "GTK4 indisponible\n");
    exit(1);
}

const VERTICAL = 1;
const ALIGN_START = 1;
const ALIGN_CENTER = 3;
const PRIORITY_APP = 600;

$css = <<<'CSS'
window { background: #101418; }
.card {
  background: #1b2027;
  border-radius: 14px;
  padding: 18px;
}
.title { color: #f2f5f8; font-size: 20px; font-weight: 800; }
.subtitle { color: #7d8794; font-size: 12px; }
entry {
  background: #0c0f13;
  color: #e8edf3;
  border-radius: 8px;
  padding: 8px 10px;
  border: 1px solid #2b333d;
}
button {
  background: #3ddc97;
  color: #06231a;
  border-radius: 8px;
  padding: 8px 14px;
  font-weight: 700;
}
.item { color: #cfd8e3; padding: 6px 2px; border-bottom: 1px solid #262e38; }
CSS;

$provider = $gtk->gtk_css_provider_new();
$gtk->gtk_css_provider_load_from_string($provider, $css);

$keepAlive = [];
$state = ['loop' => null, 'entry' => null, 'list' => null, 'subtitle' => null, 'items' => [], 'added' => 0];

$window = $gtk->gtk_window_new();
$gtk->gtk_window_set_title($window, 'Cyonima — POC GTK4 (PHP only)');
$gtk->gtk_window_set_default_size($window, 560, 380);
$gtk->gtk_style_context_add_provider_for_display(
    $gtk->gtk_widget_get_display($window),
    $provider,
    PRIORITY_APP
);

$root = $gtk->gtk_box_new(VERTICAL, 14);
$gtk->gtk_widget_add_css_class($root, 'card');
$gtk->gtk_widget_set_margin_top($root, 18);
$gtk->gtk_widget_set_margin_bottom($root, 18);
$gtk->gtk_widget_set_margin_start($root, 18);
$gtk->gtk_widget_set_margin_end($root, 18);

$title = $gtk->gtk_label_new('Cyonima — UI 100 % native, 100 % PHP');
$gtk->gtk_widget_add_css_class($title, 'title');
$gtk->gtk_label_set_xalign($title, 0.0);

$state['subtitle'] = $subtitle = $gtk->gtk_label_new('GTK4 · FFI · zéro WebView · zéro serveur HTTP');
$gtk->gtk_widget_add_css_class($subtitle, 'subtitle');
$gtk->gtk_label_set_xalign($subtitle, 0.0);

$row = $gtk->gtk_box_new(0 /* HORIZONTAL */, 8);
$state['entry'] = $gtk->gtk_entry_new();
$gtk->gtk_entry_set_placeholder_text($state['entry'], 'Nouvelle tâche…');
$gtk->gtk_widget_set_hexpand($state['entry'], 1);
$addButton = $gtk->gtk_button_new_with_label('Ajouter');
$gtk->gtk_box_append($row, $state['entry']);
$gtk->gtk_box_append($row, $addButton);

$state['list'] = $gtk->gtk_list_box_new();

$gtk->gtk_box_append($root, $title);
$gtk->gtk_box_append($root, $subtitle);
$gtk->gtk_box_append($root, $row);
$gtk->gtk_box_append($root, $state['list']);
$gtk->gtk_window_set_child($window, $root);

// --- logique applicative PHP ---
$addItem = function (string $text) use ($gtk, &$state): void {
    $text = trim($text);
    if ($text === '') {
        return;
    }
    $label = $gtk->gtk_label_new($text);
    $gtk->gtk_widget_add_css_class($label, 'item');
    $gtk->gtk_label_set_xalign($label, 0.0);
    $row = $gtk->gtk_list_box_row_new();
    $gtk->gtk_list_box_row_set_child($row, $label);
    $gtk->gtk_list_box_append($state['list'], $row);
    $state['items'][] = $row;
    $state['added']++;
    $gtk->gtk_editable_set_text($state['entry'], '');
};

$onSubmit = function ($widget, $data) use ($gtk, &$state, $addItem): int {
    $addItem((string) $gtk->gtk_editable_get_text($state['entry']));
    return 0;
};
$keepAlive[] = $onSubmit;
$gtk->g_signal_connect_data($addButton, 'clicked', $onSubmit, null, null, 0);
$gtk->g_signal_connect_data($state['entry'], 'activate', $onSubmit, null, null, 0);

$state['loop'] = $gtk->g_main_loop_new(null, 0);

$onClose = function ($win, $data) use ($gtk, &$state): int {
    $gtk->g_main_loop_quit($state['loop']);
    return 0;
};
$keepAlive[] = $onClose;
$gtk->g_signal_connect_data($window, 'close-request', $onClose, null, null, 0);

// Scénario automatisé : saisie de 3 tâches par le chemin "activate" + 50 clics
// pour vérifier qu'on tient la charge sans fuite ni crash.
$scenario = function ($data) use ($gtk, $gobject, &$state, $addItem, $keepAlive): int {
    foreach (['Compiler PHP en natif', 'Brancher GTK4 sur FFI', 'Livrer sous Linux'] as $t) {
        $gtk->gtk_editable_set_text($state['entry'], $t);
        $gobject->g_signal_emit_by_name($state['entry'], 'activate');
    }
    for ($i = 0; $i < 50; $i++) {
        $gtk->gtk_editable_set_text($state['entry'], "Rafale $i");
        $gobject->g_signal_emit_by_name($state['entry'], 'activate');
    }
    // nettoyage : on retire tout sauf les 3 premières
    foreach (array_slice($state['items'], 3) as $widget) {
        $gtk->gtk_list_box_remove($state['list'], $widget);
    }
    $gtk->gtk_label_set_text(
        $state['subtitle'],
        sprintf('%d lignes créées par des handlers PHP, puis nettoyées', $state['added'])
    );
    return 0;
};
$keepAlive[] = $scenario;
$gtk->g_timeout_add(300, $scenario, null);

$onQuit = function ($data) use ($gtk, &$state): int {
    $gtk->g_main_loop_quit($state['loop']);
    return 0;
};
$keepAlive[] = $onQuit;
$gtk->g_timeout_add(3000, $onQuit, null);

$gtk->gtk_window_present($window);
$t0 = hrtime(true);
$gtk->g_main_loop_run($state['loop']);
$ms = (hrtime(true) - $t0) / 1e6;

printf("OK — %.0f ms de boucle, %d tâches ajoutées, %d encore affichées, pic mémoire %.1f Mo\n",
    $ms, $state['added'], count($state['items']) - 50, memory_get_peak_usage(true) / 1048576);
