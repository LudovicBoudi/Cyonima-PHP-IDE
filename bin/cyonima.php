#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$arguments = array_slice($argv, 1);

if (in_array('--version', $arguments, true)) {
    echo "Cyonima 0.1.0\n";
    exit(0);
}

if (in_array('--help', $arguments, true)) {
    echo <<<HELP
    Cyonima — IDE PHP natif GTK4

    Usage : cyonima [options]

      --auto       scénario automatique (démonstration, fermeture seule)
      --version    affiche la version
      --help       affiche cette aide

    Prérequis : PHP 8.3+ (ext-ffi), GTK4, libadwaita, GtkSourceView 5, VTE.
    Variables : CYONIMA_LSP (serveur LSP), CYONIMA_XDEBUG_PORT (débogueur).

    HELP;
    exit(0);
}

require_once $root . '/autoload.php';

require $root . '/examples/ide.php';
