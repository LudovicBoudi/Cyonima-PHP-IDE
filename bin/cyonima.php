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

      --project=DIR  ouvre directement l'IDE sur un projet
      --auto         scénario automatique (démonstration, fermeture seule)
      --version      affiche la version
      --help         affiche cette aide

    Sans option, la fenêtre de démarrage propose de créer ou d'ouvrir un projet.

    Prérequis : PHP 8.3+ (ext-ffi), GTK4, libadwaita, GtkSourceView 5, VTE.
    Variables : CYONIMA_LSP (serveur LSP), CYONIMA_XDEBUG_PORT (débogueur),
    CYONIMA_CONFIG_DIR (projets récents), CYONIMA_HOME (runtime).

    HELP;
    exit(0);
}

require_once $root . '/autoload.php';

$project = null;
for ($index = 0, $count = count($arguments); $index < $count; $index++) {
    $argument = $arguments[$index];

    if (str_starts_with($argument, '--project=')) {
        $project = substr($argument, strlen('--project='));
        continue;
    }

    if ($argument === '--project' && isset($arguments[$index + 1])) {
        $project = $arguments[++$index];
    }
}

$auto = in_array('--auto', $arguments, true);

if ($project !== null && $project !== '') {
    $path = realpath($project);
    if ($path === false || !is_dir($path)) {
        fwrite(STDERR, "Projet introuvable : {$project}\n");
        exit(1);
    }

    putenv('CYONIMA_PROJECT=' . $path);
    putenv('CYONIMA_HOME=' . $root);

    require $root . '/examples/ide.php';
    exit(0);
}

if ($auto) {
    putenv('CYONIMA_HOME=' . $root);
    require $root . '/examples/ide.php';
    exit(0);
}

\Cyonima\App\StartWindow::run($root);
