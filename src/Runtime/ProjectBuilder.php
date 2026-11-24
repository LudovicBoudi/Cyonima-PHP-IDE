<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

/**
 * Lance la construction d'une application autonome à partir d'un projet.
 *
 * Le build réel est effectué par `packaging/appimage/build-project.sh` :
 * PHP, GTK, le runtime Cyonima et le projet sont regroupés dans une AppImage.
 * Cette fonctionnalité nécessite l'environnement de build (PHP embarqué déjà
 * compilé), pas seulement l'IDE packagé.
 */
final class ProjectBuilder
{
    public const FORMAT_APPIMAGE = 'appimage';
    public const FORMAT_FLATPAK = 'flatpak';

    public static function script(string $home): string
    {
        return rtrim($home, '/') . '/packaging/appimage/build-project.sh';
    }

    public static function embeddedPhp(string $home): string
    {
        return rtrim($home, '/') . '/build/appimage-php/php/usr/bin/php';
    }

    public static function bundleScript(string $home): string
    {
        return rtrim($home, '/') . '/packaging/appimage/build-project-bundle.sh';
    }

    public static function flatpakScript(string $home): string
    {
        return rtrim($home, '/') . '/packaging/flatpak/build-project-flatpak.sh';
    }

    public static function flatpakBuilderAvailable(): bool
    {
        $output = shell_exec('command -v flatpak-builder 2>/dev/null');

        return is_string($output) && trim($output) !== '';
    }

    /** @return null|string racine de l'AppImage ou du Flatpak courant */
    public static function runtimeRoot(string $home): ?string
    {
        $home = rtrim($home, '/');
        $root = dirname($home, 2);

        if (is_file($root . '/usr/bin/php') || is_file($root . '/bin/php')) {
            return $root;
        }

        return null;
    }

    public static function installedAppImageAvailable(string $home): bool
    {
        return is_file(self::script($home))
            && self::runtimeRoot($home) !== null
            && is_file(rtrim($home, '/') . '/tools/appimagetool');
    }

    public static function available(string $home, string $format = self::FORMAT_APPIMAGE): bool
    {
        if ($format === self::FORMAT_FLATPAK) {
            return is_file(self::flatpakScript($home)) && self::flatpakBuilderAvailable();
        }

        if (is_file(self::script($home)) && is_file(self::embeddedPhp($home))) {
            return true;
        }

        if (self::installedAppImageAvailable($home)) {
            return true;
        }

        return is_file(self::bundleScript($home)) && self::runtimeRoot($home) !== null;
    }

    /** @return array{ok: bool, message: string, process: null|Process} */
    public static function start(
        string $home,
        string $project,
        ?string $output = null,
        string $format = self::FORMAT_APPIMAGE,
    ): array {
        if (!self::available($home, $format)) {
            return [
                'ok' => false,
                'message' => $format === self::FORMAT_FLATPAK
                    ? 'Build Flatpak indisponible : flatpak-builder, le SDK GNOME et les scripts d’export sont requis.'
                    : 'Build autonome indisponible : la chaîne de build n’est pas présente dans cette installation de Cyonima IDE.',
                'process' => null,
            ];
        }

        if ($format === self::FORMAT_FLATPAK) {
            if ($output === null || $output === '') {
                $output = self::projectOutput($project, 'x86_64.flatpak');
            }

            $environment = getenv();
            if (!is_array($environment)) {
                $environment = [];
            }
            $environment['PHP_CLI'] = PHP_BINARY;

            return [
                'ok' => true,
                'message' => 'Construction du Flatpak en cours…',
                'process' => Process::start(
                    ['/bin/bash', self::flatpakScript($home), $project, $output],
                    null,
                    $environment,
                ),
            ];
        }

        $runtimeRoot = self::runtimeRoot($home);
        $sourceAppImage = is_file(self::script($home)) && is_file(self::embeddedPhp($home));
        $installedAppImage = self::installedAppImageAvailable($home);

        if ($sourceAppImage || $installedAppImage) {
            $command = ['/bin/bash', self::script($home), $project];

            if ($installedAppImage) {
                if ($output === null || $output === '') {
                    $output = self::projectOutput($project, 'x86_64.AppImage');
                }
                $environment = getenv();
                if (!is_array($environment)) {
                    $environment = [];
                }
                $environment['CYONIMA_RUNTIME_ROOT'] = (string) $runtimeRoot;
                $environment['PHP_CLI'] = PHP_BINARY;
            } else {
                $environment = null;
            }

            if ($output !== null && $output !== '') {
                $command[] = $output;
            }

            return [
                'ok' => true,
                'message' => 'Construction de l’AppImage en cours…',
                'process' => Process::start($command, null, $environment),
            ];
        }

        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment['CYONIMA_RUNTIME_ROOT'] = (string) $runtimeRoot;
        $environment['PHP_CLI'] = PHP_BINARY;

        if ($output === null || $output === '') {
            $output = self::projectOutput($project, 'linux-x86_64.tar.gz');
        }

        $command = ['/bin/bash', self::bundleScript($home), $project, $output];

        return [
            'ok' => true,
            'message' => 'Construction du bundle autonome en cours…',
            'process' => Process::start($command, null, $environment),
        ];
    }

    private static function projectOutput(string $project, string $suffix): string
    {
        $manifest = ProjectFactory::manifest($project);
        $name = is_string($manifest['name'] ?? null) ? $manifest['name'] : basename($project);
        $version = is_string($manifest['version'] ?? null) ? $manifest['version'] : '0.1.0';

        return rtrim($project, '/') . '/dist/'
            . ProjectFactory::slug($name) . '-' . $version . '-' . $suffix;
    }
}
