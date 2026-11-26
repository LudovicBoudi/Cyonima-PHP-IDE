<?php

declare(strict_types=1);

/**
 * Génère le manifeste Flatpak d’un projet compilé en natif.
 *
 * Usage :
 *   native-project-manifest.php MANIFESTE APP_ID SLUG [LIB...]
 *
 * Le contexte doit contenir : app-bin, app, app.desktop, icon.svg,
 * et éventuellement libs/*.so*.
 */

$output = $argv[1] ?? '';
$appId = $argv[2] ?? '';
$slug = $argv[3] ?? '';
$libs = array_slice($argv, 4);

if ($output === '' || $appId === '' || $slug === '') {
    fwrite(STDERR, "Arguments manifeste natif invalides.\n");
    exit(1);
}

$commands = [
    'install -d /app/bin /app/share/applications /app/share/icons/hicolor/scalable/apps /app/lib/' . $slug,
    'install -Dm755 app-bin /app/bin/' . $slug . '.bin',
    'install -Dm755 app /app/bin/' . $slug,
    'install -Dm644 app.desktop /app/share/applications/' . $appId . '.desktop',
    'install -Dm644 icon.svg /app/share/icons/hicolor/scalable/apps/' . $appId . '.svg',
];

foreach ($libs as $lib) {
    $base = basename($lib);

    if ($base === '') {
        continue;
    }

    $commands[] = 'install -Dm644 libs/' . $base . ' /app/lib/' . $slug . '/' . $base;
}

$manifest = [
    'app-id' => $appId,
    'runtime' => 'org.gnome.Platform',
    'runtime-version' => '49',
    'sdk' => 'org.gnome.Sdk',
    'command' => $slug,
    'finish-args' => [
        '--share=ipc',
        '--socket=fallback-x11',
        '--socket=wayland',
        '--device=dri',
        '--share=network',
        '--filesystem=home',
    ],
    'modules' => [
        [
            'name' => 'cyonima-native',
            'buildsystem' => 'simple',
            'build-commands' => $commands,
            'sources' => [
                ['type' => 'dir', 'path' => '.'],
            ],
        ],
    ],
];

$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($json === false || file_put_contents($output, $json . "\n") === false) {
    fwrite(STDERR, "Impossible d'écrire le manifeste Flatpak natif.\n");
    exit(1);
}
