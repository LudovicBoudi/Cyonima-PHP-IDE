<?php

/**
 * Vérifie la cohérence des artefacts de packaging sans construire.
 * Utilisable en CI : manifeste Flatpak, fichier .desktop, icône, lanceurs.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];

// --- Manifeste Flatpak ------------------------------------------------------
$manifestPath = $root . '/packaging/flatpak/com.cyonima.Ide.json';
$manifest = is_file($manifestPath)
    ? json_decode((string) file_get_contents($manifestPath), true)
    : null;

if (!is_array($manifest)) {
    $errors[] = 'manifeste Flatpak : JSON invalide ou absent';
} else {
    $appId = (string) ($manifest['app-id'] ?? '');
    if (preg_match('/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)+$/', $appId) !== 1) {
        $errors[] = "manifeste : app-id invalide ({$appId})";
    }

    if (empty($manifest['runtime']) || empty($manifest['sdk'])) {
        $errors[] = 'manifeste : runtime ou sdk manquant';
    }

    $command = (string) ($manifest['command'] ?? '');
    if ($command === '') {
        $errors[] = 'manifeste : command manquante';
    }

    $installed = false;
    foreach ($manifest['modules'] ?? [] as $module) {
        foreach ($module['build-commands'] ?? [] as $buildCommand) {
            if ($command !== '' && str_contains($buildCommand, '/app/bin/' . $command)) {
                $installed = true;
            }
        }
        foreach ($module['sources'] ?? [] as $source) {
            if (($source['type'] ?? '') !== 'dir') {
                continue;
            }
            $path = dirname($manifestPath) . '/' . $source['path'];
            if (realpath($path) === false) {
                $errors[] = 'manifeste : source locale introuvable (' . $source['path'] . ')';
            }
        }
    }

    if ($command !== '' && !$installed) {
        $errors[] = "manifeste : la commande {$command} n'est installée dans aucun /app/bin";
    }
}

// --- Fichier .desktop -------------------------------------------------------
$desktopPath = $root . '/packaging/com.cyonima.Ide.desktop';
$desktop = [];

if (is_file($desktopPath)) {
    foreach (file($desktopPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === '[' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $desktop[trim($key)] = trim($value);
    }
}

if ($desktop === []) {
    $errors[] = 'desktop : fichier illisible ou vide';
} else {
    foreach (['Type', 'Name', 'Exec', 'Icon', 'Categories'] as $key) {
        if (empty($desktop[$key])) {
            $errors[] = "desktop : {$key} manquant";
        }
    }
    if (($desktop['Type'] ?? '') !== 'Application') {
        $errors[] = 'desktop : Type doit être Application';
    }
    if (isset($desktop['Categories']) && !str_ends_with($desktop['Categories'], ';')) {
        $errors[] = 'desktop : Categories doit se terminer par un point-virgule';
    }
}

// --- Icône et lanceurs ------------------------------------------------------
if (!is_file($root . '/packaging/icon/com.cyonima.Ide.svg')) {
    $errors[] = 'icône SVG manquante';
}

foreach ([
    'bin/cyonima',
    'bin/cyonima.php',
    'packaging/AppRun',
    'packaging/build-portable.sh',
    'packaging/test-portable.sh',
    'packaging/test-flatpak.sh',
    'packaging/appimage/AppRun',
    'packaging/appimage/build-php.sh',
    'packaging/appimage/build-appimage.sh',
    'packaging/appimage/bundle-gtk.sh',
    'packaging/test-appimage.sh',
] as $file) {
    if (!is_file($root . '/' . $file)) {
        $errors[] = "fichier manquant : {$file}";
        continue;
    }
    if (!is_executable($root . '/' . $file) && !str_ends_with($file, '.php')) {
        $errors[] = "{$file} n'est pas exécutable";
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Packaging invalide :\n  - " . implode("\n  - ", $errors) . "\n");
    exit(1);
}

echo "Packaging valide : manifeste Flatpak, desktop, icône et lanceurs.\n";
