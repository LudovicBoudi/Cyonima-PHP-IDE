<?php

declare(strict_types=1);

/**
 * Exemple de widgets enrichis : case à cocher, barre de progression, CSS.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-widgets.php --emit=elf
 *   ./dist/native-widgets
 */

$application = new Application('com.cyonima.widgets.demo');
$application->loadCss(
    '.panel { padding: 16px; background: #101418; }'
    . '.title { font-size: 20px; font-weight: bold; color: #e6edf3; }'
    . '.state { color: #8b949e; }'
    . 'checkbutton { color: #e6edf3; }'
);

$window = new Window('Widgets Cyonima', 420, 280);
$box = new Box(true, 10);
$box->addClass('panel');

$title = new Label('Widgets enrichis');
$title->addClass('title');

$progress = new ProgressBar();
$progress->setFraction(0.0);

$state = new Label('0 %');
$state->addClass('state');

$check = new CheckButton('Activer la progression', false);
$check->setTooltip('Bascule la barre entre 0 % et 100 %');

$check->onToggled(function () use ($progress, $state, $check): void {
    if ($check->active()) {
        $progress->setFraction(1.0);
        $state->setText('100 %');
    } else {
        $progress->setFraction(0.0);
        $state->setText('0 %');
    }
});

$box->append($title);
$box->append($progress);
$box->append($state);
$box->append(new Separator(false));
$box->append($check);

$window->setChild($box);
$window->present();
$application->run();
