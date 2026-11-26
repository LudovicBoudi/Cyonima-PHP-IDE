<?php

declare(strict_types=1);

/**
 * Exemple de binding bidirectionnel widget ↔ état.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-binding.php --emit=elf
 *   ./dist/native-binding
 */

$application = new Application('com.cyonima.binding.demo');

$name = 'Cyonima';
$active = true;

$window = new Window('Binding', 440, 320);
$box = new Box(true, 10);
$box->addClass('panel');

$entry = new Entry('Nom');
$check = new CheckButton('Actif', true);
$summary = new Label('');
$refresh = new Button('Rafraîchir le résumé');
$quit = new Button('Quitter');

$entryBinding = Binding::entry($entry, function () use (&$name): string {
    return $name;
}, function (string $value) use (&$name): void {
    $name = $value;
});

$checkBinding = Binding::check($check, function () use (&$active): bool {
    return $active;
}, function (bool $value) use (&$active): void {
    $active = $value;
});

$summaryBinding = Binding::label($summary, function () use (&$name, &$active): string {
    if ($active) {
        return $name . ' — actif';
    }

    return $name . ' — inactif';
});

$refresh->onClicked(function () use ($summaryBinding): void {
    $summaryBinding->refresh();
});

$quit->onClicked(function () use ($application): void {
    $application->quit();
});

$box->append(new Label('Nom'));
$box->append($entry);
$box->append($check);
$box->append($summary);
$box->append($refresh);
$box->append($quit);

$window->setChild($box);
$window->shortcut('<Control>q', function () use ($application): void {
    $application->quit();
});
$window->present();
$application->run();
