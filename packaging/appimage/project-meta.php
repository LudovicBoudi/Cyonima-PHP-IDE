<?php

declare(strict_types=1);

/**
 * Lit les métadonnées d'un projet pour le script de build.
 *
 * Sortie, une valeur par ligne :
 *   nom, slug, appId, entrée, version
 */

$root = $argv[1] ?? '';
$manifestPath = rtrim($root, '/') . '/.cyonima/project.json';
$manifest = is_file($manifestPath)
    ? json_decode((string) file_get_contents($manifestPath), true)
    : null;

if (!is_array($manifest)) {
    fwrite(STDERR, "Manifeste introuvable : {$manifestPath}\n");
    exit(1);
}

$name = is_string($manifest['name'] ?? null) && trim($manifest['name']) !== ''
    ? trim($manifest['name'])
    : basename(realpath($root) ?: $root);

$ascii = strtr($name, [
    'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
    'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
    'ç' => 'c', 'œ' => 'oe', 'æ' => 'ae',
    'À' => 'a', 'Â' => 'a', 'Ä' => 'a', 'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
    'Î' => 'i', 'Ï' => 'i', 'Ô' => 'o', 'Ö' => 'o', 'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u',
    'Ç' => 'c', 'Œ' => 'oe', 'Æ' => 'ae',
]);
$slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
$slug = $slug === '' ? 'application' : $slug;

$appId = is_string($manifest['appId'] ?? null) && trim($manifest['appId']) !== ''
    ? trim($manifest['appId'])
    : 'com.example.' . str_replace('-', '', $slug);

$entry = is_string($manifest['entry'] ?? null) && trim($manifest['entry']) !== ''
    ? ltrim(trim($manifest['entry']), '/')
    : 'src/main.php';

$version = is_string($manifest['version'] ?? null) && trim($manifest['version']) !== ''
    ? trim($manifest['version'])
    : '0.1.0';

echo $name . "\n";
echo $slug . "\n";
echo $appId . "\n";
echo $entry . "\n";
echo $version . "\n";
