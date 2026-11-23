<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

use Cyonima\Runtime\AdwApplication;
use Cyonima\Runtime\Loop;
use Cyonima\UI\ActionRow;
use Cyonima\UI\Button;
use Cyonima\UI\Clamp;
use Cyonima\UI\PreferencesGroup;
use Cyonima\UI\PreferencesPage;
use Cyonima\UI\SwitchRow;

/**
 * Fenêtre libadwaita complète : HeaderBar + WindowTitle, ToolbarView, Clamp,
 * PreferencesPage/Group/Row, SwitchRow et toasts — sans une ligne de C.
 */

$auto = in_array('--auto', $argv, true);

$app = new AdwApplication('com.cyonima.adw-demo');

$state = new ActionRow('État du runtime', 'Prêt');
$compact = new SwitchRow('Mode compact', "Réduit les marges de l'interface");
$compact->onChanged(function (SwitchRow $row) use ($state): void {
    $state->subtitle($row->isActive() ? 'Compact activé' : 'Compact désactivé');
});

$theme = (new ActionRow('Thème', 'Suivi du système'))->icon('applications-graphics-symbolic');
$runtime = (new ActionRow('Moteur', 'GTK4 · FFI · fibres PHP'))->icon('utilities-terminal-symbolic');

$window = $app->window('Cyonima', 'libadwaita · GTK4 · PHP');

$about = (new Button('À propos'))->onClicked(
    fn () => $window->toast('Cyonima M3 — UI native, 100 % PHP', 2500),
);

$page = (new PreferencesPage('Réglages', 'Une page native construite depuis PHP'))
    ->add(
        (new PreferencesGroup('Apparence'))->add($compact, $theme),
        (new PreferencesGroup('Runtime'))->add($runtime, $state),
    );

$window
    ->headerEnd($about)
    ->content(new Clamp($page, 560))
    ->present();

if ($auto) {
    Loop::after(250, fn () => $compact->active(true));
    Loop::after(450, fn () => $about->click());
    Loop::after(1400, fn () => $window->raw()->close());
}

$app->run();

printf(
    "Compact : %s | État : %s | Fenêtres restantes : %d\n",
    $compact->isActive() ? 'activé' : 'désactivé',
    (string) $state->raw()->getSubtitle(),
    count($app->windows()),
);
