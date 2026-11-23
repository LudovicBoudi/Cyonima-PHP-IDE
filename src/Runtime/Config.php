<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

/**
 * Répertoire de configuration de l'utilisateur (projets récents, préférences).
 *
 * `CYONIMA_CONFIG_DIR` isole un profil (tests, bundle portable, démonstration).
 */
final class Config
{
    public static function dir(): string
    {
        $override = getenv('CYONIMA_CONFIG_DIR');
        if ($override !== false && trim($override) !== '') {
            return rtrim($override, '/');
        }

        $base = getenv('XDG_CONFIG_HOME');
        if ($base === false || trim($base) === '') {
            $home = getenv('HOME');
            $base = ($home === false || trim($home) === '')
                ? sys_get_temp_dir()
                : rtrim($home, '/') . '/.config';
        }

        return rtrim($base, '/') . '/cyonima';
    }

    public static function file(string $name): string
    {
        return self::dir() . '/' . ltrim($name, '/');
    }
}
