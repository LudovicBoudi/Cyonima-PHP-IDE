<?php

declare(strict_types=1);

/**
 * Application GTK4 native compilée par Cyonima.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-ui.php --emit=elf
 *   ./dist/native-ui
 *
 * La compilation ELF nécessite GTK4 de développement (libgtk-4-dev).
 * L’interpréteur PHP ne connaît pas les fonctions ui_* : ce fichier est
 * destiné au compilateur natif.
 */

function show(int $label): void
{
    ui_set_text($label, 'Cliqué !');
}

function quit(): void
{
    ui_quit();
}

ui_init('com.cyonima.nativeui');
$window = ui_window('Cyonima natif', 400, 200);
$box = ui_box(true, 12);
$label = ui_label('Bonjour');
$button = ui_button('Cliquer');
$quitButton = ui_button('Quitter');
ui_append($box, $label);
ui_append($box, $button);
ui_append($box, $quitButton);
ui_set_child($window, $box);
ui_on_click($button, 'show', $label);
ui_on_click($quitButton, 'quit');
ui_present($window);
ui_run();
