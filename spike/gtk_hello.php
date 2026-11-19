<?php
/**
 * POC Cyonima : fenêtre GTK4 native pilotée 100% depuis PHP via FFI.
 * Aucun shim C, aucun serveur HTTP, aucune WebView.
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

void *gtk_label_new(const char *text);
void  gtk_label_set_text(void *label, const char *text);
void *gtk_button_new_with_label(const char *text);

unsigned long g_signal_connect_data(
    void *instance, const char *signal,
    int (*handler)(void *, void *),
    void *data, void *destroy, int flags);

void *g_main_loop_new(void *context, int is_running);
void  g_main_loop_run(void *loop);
void  g_main_loop_quit(void *loop);
unsigned int g_timeout_add(unsigned int interval, int (*handler)(void *), void *data);
C, 'libgtk-4.so.1');

$gobject = FFI::cdef(
    'int g_signal_emit_by_name(void *instance, const char *signal, ...);',
    'libgobject-2.0.so.0'
);

if (!$gtk->gtk_init_check()) {
    fwrite(STDERR, "GTK4 : impossible d'initialiser le display\n");
    exit(1);
}

// Garde les closures en vie : sans ça le GC peut libérer le trampoline C.
$keepAlive = [];
$state = ['clicks' => 0, 'label' => null, 'loop' => null];

$window = $gtk->gtk_window_new();
$gtk->gtk_window_set_title($window, 'Cyonima — POC GTK4 natif depuis PHP');
$gtk->gtk_window_set_default_size($window, 520, 220);

$box = $gtk->gtk_box_new(1 /* GTK_ORIENTATION_VERTICAL */, 18);
$gtk->gtk_widget_set_halign($box, 3 /* GTK_ALIGN_CENTER */);
$gtk->gtk_widget_set_valign($box, 3);
$gtk->gtk_widget_set_margin_top($box, 28);
$gtk->gtk_widget_set_margin_bottom($box, 28);

$state['label'] = $gtk->gtk_label_new('Clics : 0');
$button = $gtk->gtk_button_new_with_label('Cliquer (handler PHP)');

$gtk->gtk_box_append($box, $state['label']);
$gtk->gtk_box_append($box, $button);
$gtk->gtk_window_set_child($window, $box);

// --- Callbacks : PHP exécuté depuis la boucle d'événements C de GTK ---
$onClick = function ($btn, $data) use ($gtk, &$state): int {
    $state['clicks']++;
    $gtk->gtk_label_set_text(
        $state['label'],
        sprintf('Clics : %d — handler PHP appelé par GTK', $state['clicks'])
    );
    return 0;
};
$keepAlive[] = $onClick;
$gtk->g_signal_connect_data($button, 'clicked', $onClick, null, null, 0);

$state['loop'] = $gtk->g_main_loop_new(null, 0);

$onClose = function ($win, $data) use ($gtk, &$state): int {
    $gtk->g_main_loop_quit($state['loop']);
    return 0;
};
$keepAlive[] = $onClose;
$gtk->g_signal_connect_data($window, 'close-request', $onClose, null, null, 0);

// Preuve n°2 : on émet un clic synthétique depuis PHP (comme le ferait un test).
$onSimulate = function ($data) use ($gobject, $button): int {
    $gobject->g_signal_emit_by_name($button, 'clicked');
    return 0;
};
$keepAlive[] = $onSimulate;
$gtk->g_timeout_add(250, $onSimulate, null);

// Auto-fermeture du POC après 2,5 s.
$onQuit = function ($data) use ($gtk, &$state): int {
    $gtk->g_main_loop_quit($state['loop']);
    return 0;
};
$keepAlive[] = $onQuit;
$gtk->g_timeout_add(2500, $onQuit, null);

$gtk->gtk_window_present($window);
$start = hrtime(true);
$gtk->g_main_loop_run($state['loop']);
$ms = (hrtime(true) - $start) / 1e6;

printf(
    "OK — boucle GTK terminée en %.0f ms, %d clic(s) traité(s) par du PHP.\n",
    $ms,
    $state['clicks']
);
