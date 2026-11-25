<?php

declare(strict_types=1);

/**
 * Génère le manifeste flatpak-builder d'une application projet.
 *
 * Usage : project-manifest.php <sortie.json> <nom> <slug> <appId> <entrée>
 */

$output = $argv[1] ?? '';
$name = $argv[2] ?? 'Application';
$slug = $argv[3] ?? 'application';
$appId = $argv[4] ?? 'com.example.application';
$entry = $argv[5] ?? 'src/main.php';

if ($output === '') {
    fwrite(STDERR, "Sortie manifeste requise.\n");
    exit(1);
}

$phpModule = [
    'name' => 'php',
    'buildsystem' => 'simple',
    'build-commands' => [
        'export PKG_CONFIG_PATH="/app/lib/pkgconfig:/app/lib64/pkgconfig:${PKG_CONFIG_PATH:-}"; ./configure --prefix=/app --disable-all --enable-ctype --enable-cli --with-ffi --with-openssl --enable-sockets --enable-pcntl --enable-posix --with-curl --enable-intl --enable-pdo --with-pdo-sqlite --with-sqlite3 --with-zip --enable-gd --with-jpeg --with-freetype --with-webp --enable-simplexml --enable-dom --with-libxml',
        'make -j${FLATPAK_BUILDER_N_JOBS:-4}',
        'make install',
    ],
    'sources' => [[
        'type' => 'archive',
        'url' => 'https://www.php.net/distributions/php-8.3.14.tar.xz',
        'sha256' => '58b4cb9019bf70c0cbcdb814c7df79b9065059d14cf7dbf48d971f8e56ae9be7',
    ]],
];

$launcher = <<<'SH'
#!/bin/sh
set -eu
export CYONIMA_HOME=/app/share/cyonima
cd /app/share/app
exec /app/bin/php -d ffi.enable=1 "/app/share/app/__ENTRY__" "$@"
SH;
$launcher = str_replace('__ENTRY__', $entry, $launcher);
file_put_contents(dirname($output) . '/launcher', $launcher);
chmod(dirname($output) . '/launcher', 0755);

$desktop = <<<DESKTOP
[Desktop Entry]
Type=Application
Name={$name}
Comment=Application créée avec Cyonima IDE
Exec={$slug}
Icon={$appId}
Terminal=false
Categories=Utility;
StartupNotify=true
StartupWMClass={$appId}
DESKTOP;
file_put_contents(dirname($output) . '/app.desktop', $desktop);

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
    ],
    'modules' => [
        [
            'name' => 'libzip',
            'buildsystem' => 'cmake',
            'config-opts' => ['-DBUILD_DOC=OFF', '-DBUILD_EXAMPLES=OFF', '-DBUILD_REGRESS=OFF', '-DENABLE_COMMONCRYPTO=OFF', '-DENABLE_GNUTLS=OFF', '-DENABLE_MBEDTLS=OFF', '-DENABLE_OPENSSL=OFF'],
            'sources' => [[
                'type' => 'archive',
                'url' => 'https://libzip.org/download/libzip-1.10.1.tar.gz',
                'sha256' => '9669ae5dfe3ac5b3897536dc8466a874c8cf2c0e3b1fdd08d75b273884299363',
            ]],
        ],
        $phpModule,
        [
            'name' => 'libssh2',
            'buildsystem' => 'autotools',
            'config-opts' => ['--with-openssl', '--disable-docs'],
            'sources' => [[
                'type' => 'archive',
                'url' => 'https://www.libssh2.org/download/libssh2-1.11.0.tar.gz',
                'sha256' => '3736161e41e2693324deb38c26cfdc3efe6209d634ba4258db1cecff6a5ad461',
            ]],
        ],
        [
            'name' => 'php-ssh2',
            'buildsystem' => 'simple',
            'build-commands' => [
                'phpize',
                './configure --with-php-config=/app/bin/php-config --with-ssh2=/app',
                'make -j${FLATPAK_BUILDER_N_JOBS:-4}',
                'make install',
                'printf \'extension=ssh2\\n\' > /app/lib/php.ini',
            ],
            'sources' => [[
                'type' => 'archive',
                'url' => 'https://pecl.php.net/get/ssh2-1.4.1.tgz',
                'sha256' => '7bca5b23f731db9d8ed0aea5db9bb15da8ff133b0fbba96102b82e95da4d8764',
            ]],
        ],
        [
            'name' => 'cyonima-app',
            'buildsystem' => 'simple',
            'build-commands' => [
                'install -d /app/share/app /app/share/cyonima /app/bin /app/share/applications /app/share/icons/hicolor/scalable/apps',
                'cp -r app/. /app/share/app/',
                'cp -r cyonima/. /app/share/cyonima/',
                'install -Dm755 launcher /app/bin/' . $slug,
                'install -Dm644 app.desktop /app/share/applications/' . $appId . '.desktop',
                'install -Dm644 icon.svg /app/share/icons/hicolor/scalable/apps/' . $appId . '.svg',
            ],
            'sources' => [
                ['type' => 'dir', 'path' => '.'],
            ],
        ],
    ],
];

$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false || file_put_contents($output, $json . "\n") === false) {
    fwrite(STDERR, "Impossible d'écrire le manifeste Flatpak.\n");
    exit(1);
}
