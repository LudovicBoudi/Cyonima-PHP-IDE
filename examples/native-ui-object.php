<?php

declare(strict_types=1);

/**
 * Façade objet UI compilée.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-ui-object.php --emit=elf
 *   ./dist/native-ui-object
 *
 * La composition utilise encore les handles natifs (`$widget->handle`) dans
 * cette première version. Fermer la fenêtre arrête la boucle GTK.
 */

$app = new Application('com.cyonima.nativeui.object');
$window = new Window('Cyonima objet', 400, 200);
$box = new Box(true, 12);
$label = new Label('Bonjour');
$button = new Button('Cliquer');

$box->append($label);
$box->append($button);
$window->setChild($box);

$button->onClicked(function () use ($label): void {
    $label->setText('Cliqué !');
});

$window->present();
$app->run();
