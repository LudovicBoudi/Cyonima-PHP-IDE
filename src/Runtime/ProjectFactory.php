<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

use RuntimeException;

/**
 * Fabrique d'environnements de projet.
 *
 * Chaque modèle écrit un projet autonome : code source, manifeste dans
 * `.cyonima/project.json`, assets (icône SVG, thème CSS), licence, README et
 * fichiers de configuration d'éditeur.
 */
final class ProjectFactory
{
    public const TEMPLATE_GTK = 'gtk';
    public const TEMPLATE_CONSOLE = 'console';
    public const TEMPLATE_LIBRARY = 'library';

    public const MANIFEST = '.cyonima/project.json';

    /** @return array<string, array{name: string, description: string, icon: string, accent: string}> */
    public static function templates(): array
    {
        return [
            self::TEMPLATE_GTK => [
                'name' => 'Application GTK4',
                'description' => 'Fenêtre native libadwaita, thème VS dark et assets prêts.',
                'icon' => 'applications-graphics-symbolic',
                'accent' => '#007acc',
            ],
            self::TEMPLATE_CONSOLE => [
                'name' => 'Application console',
                'description' => 'Script PHP 8.3 sans interface, idéal pour les outils.',
                'icon' => 'utilities-terminal-symbolic',
                'accent' => '#dcdcaa',
            ],
            self::TEMPLATE_LIBRARY => [
                'name' => 'Bibliothèque',
                'description' => 'Package PSR-4 avec classe, test et README.',
                'icon' => 'package-x-generic-symbolic',
                'accent' => '#4ec9b0',
            ],
        ];
    }

    /** @return array<string, string> */
    public static function licenses(): array
    {
        return [
            'MIT' => 'MIT License',
            'Apache-2.0' => 'Apache License 2.0',
            'GPL-3.0' => 'GNU GPL v3.0',
            'Proprietary' => 'Propriétaire — tous droits réservés',
        ];
    }

    public static function defaultLocation(): string
    {
        $home = getenv('HOME');

        return ($home === false || trim($home) === '' ? getcwd() : rtrim($home, '/')) . '/CyonimaProjects';
    }

    /**
     * @param list<string> $extraAssets chemins de fichiers à copier dans assets/
     * @return array{ok: bool, message: string, path: null|string, manifest: null|array, warnings: list<string>}
     */
    public static function create(
        string $name,
        string $location,
        string $template = self::TEMPLATE_GTK,
        string $license = 'MIT',
        ?string $appId = null,
        array $extraAssets = [],
        ?string $home = null,
    ): array {
        $warnings = [];
        $name = trim($name);

        if ($name === '') {
            return self::failure('Le nom du projet est obligatoire.');
        }

        if (strlen($name) > 100) {
            return self::failure('Le nom du projet est trop long (100 caractères max).');
        }

        if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._-]*$/u', $name)) {
            return self::failure('Nom invalide : lettres, chiffres, espaces, point, tiret et souligné.');
        }

        $templates = self::templates();
        if (!isset($templates[$template])) {
            return self::failure('Modèle inconnu : ' . $template);
        }

        $licenses = self::licenses();
        if (!isset($licenses[$license])) {
            return self::failure('Licence inconnue : ' . $license);
        }

        $slug = self::slug($name);
        $appId = $appId !== null && trim($appId) !== ''
            ? trim($appId)
            : self::suggestAppId($name);

        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z][A-Za-z0-9_]*)+$/', $appId)) {
            return self::failure('Identifiant d\'application invalide (ex. com.exemple.monapp).');
        }

        $location = self::normalizePath($location === '' ? self::defaultLocation() : $location);
        if (!is_dir($location) && !mkdir($location, 0775, true) && !is_dir($location)) {
            return self::failure('Impossible de créer l\'emplacement : ' . $location);
        }

        if (!is_writable($location)) {
            return self::failure('Emplacement non accessible en écriture : ' . $location);
        }

        $target = $location . '/' . $slug;
        if (is_file($target)) {
            return self::failure('Un fichier porte déjà ce nom : ' . $target);
        }

        if (is_dir($target) && !self::isDirectoryEmpty($target)) {
            return self::failure('Le dossier existe déjà et n\'est pas vide : ' . $target);
        }

        if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
            return self::failure('Impossible de créer le dossier : ' . $target);
        }

        $runtimeHome = $home
            ?? (getenv('CYONIMA_HOME') ?: dirname(__DIR__, 2));

        $year = gmdate('Y');
        $accent = $templates[$template]['accent'];
        $entry = $template === self::TEMPLATE_LIBRARY ? 'tests/test.php' : 'src/main.php';

        try {
            self::writeFile($target . '/autoload.php', self::autoloadTemplate($runtimeHome));
            self::writeFile($target . '/.gitignore', self::GITIGNORE);
            self::writeFile($target . '/.editorconfig', self::EDITORCONFIG);
            self::writeFile($target . '/LICENSE', self::licenseText($license, $name, $year));
            self::writeFile($target . '/README.md', self::readme($name, $template, $appId, $license, $entry));
            self::writeFile($target . '/assets/icon.svg', self::iconSvg($name, $accent));
            self::writeFile($target . '/assets/theme.css', self::THEME_CSS);
            self::writeFile($target . '/assets/README.md', self::ASSETS_README);

            match ($template) {
                self::TEMPLATE_CONSOLE => self::writeFile(
                    $target . '/src/main.php',
                    self::consoleTemplate($name),
                ),
                self::TEMPLATE_LIBRARY => self::writeLibrary($target, $name),
                default => self::writeFile(
                    $target . '/src/main.php',
                    self::gtkTemplate($name, $appId),
                ),
            };
        } catch (RuntimeException $exception) {
            return self::failure($exception->getMessage(), $target);
        }

        $manifest = [
            'name' => $name,
            'slug' => $slug,
            'version' => '0.1.0',
            'template' => $template,
            'templateName' => $templates[$template]['name'],
            'appId' => $appId,
            'php' => '>=8.3',
            'createdAt' => gmdate('c'),
            'createdBy' => 'Cyonima 0.1.0',
            'license' => ['spdx' => $license, 'name' => $licenses[$license]],
            'entry' => $entry,
            'assets' => [],
        ];

        self::writeManifest($target, $manifest);

        foreach ($extraAssets as $source) {
            $source = trim($source);
            if ($source === '') {
                continue;
            }

            $result = self::importAsset($target, $source);
            if (!$result['ok']) {
                $warnings[] = $result['message'];
            }
        }

        $manifest = self::manifest($target) ?? $manifest;

        return [
            'ok' => true,
            'message' => 'Projet « ' . $name . ' » créé dans ' . $target,
            'path' => $target,
            'manifest' => $manifest,
            'warnings' => $warnings,
        ];
    }

    /** @return null|array<string, mixed> */
    public static function manifest(string $root): ?array
    {
        $path = rtrim($root, '/') . '/' . self::MANIFEST;
        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /** @return array{ok: bool, message: string, license: null|string} */
    public static function updateLicense(string $root, string $license): array
    {
        $licenses = self::licenses();

        if (!isset($licenses[$license])) {
            return ['ok' => false, 'message' => 'Licence inconnue : ' . $license, 'license' => null];
        }

        $manifest = self::manifest($root);
        if ($manifest === null) {
            return [
                'ok' => false,
                'message' => 'Aucun manifeste de projet : .cyonima/project.json manquant.',
                'license' => null,
            ];
        }

        $name = is_string($manifest['name'] ?? null) && $manifest['name'] !== ''
            ? $manifest['name']
            : basename(realpath($root) ?: $root);

        $year = gmdate('Y');
        $createdAt = is_string($manifest['createdAt'] ?? null)
            ? strtotime($manifest['createdAt'])
            : false;
        if ($createdAt !== false) {
            $year = date('Y', $createdAt);
        }

        try {
            self::writeFile(rtrim($root, '/') . '/LICENSE', self::licenseText($license, $name, $year));

            $manifest['license'] = ['spdx' => $license, 'name' => $licenses[$license]];
            $manifest['updatedAt'] = gmdate('c');
            self::writeManifest($root, $manifest);
        } catch (RuntimeException $exception) {
            return ['ok' => false, 'message' => $exception->getMessage(), 'license' => null];
        }

        return [
            'ok' => true,
            'message' => 'Licence du projet mise à jour : ' . $licenses[$license],
            'license' => $license,
        ];
    }

    public static function projectName(string $root): string
    {
        $manifest = self::manifest($root);

        return is_string($manifest['name'] ?? null) && $manifest['name'] !== ''
            ? $manifest['name']
            : basename(realpath($root) ?: $root);
    }

    /**
     * @return list<array{name: string, path: string, absolute: string, kind: string, size: int}>
     */
    public static function listAssets(string $root): array
    {
        $directory = rtrim($root, '/') . '/assets';
        if (!is_dir($directory)) {
            return [];
        }

        $files = glob($directory . '/*');
        if ($files === false) {
            return [];
        }

        $assets = [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $assets[] = [
                'name' => basename($file),
                'path' => 'assets/' . basename($file),
                'absolute' => $file,
                'kind' => self::assetKind($file),
                'size' => (int) filesize($file),
            ];
        }

        usort($assets, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $assets;
    }

    /** @return array{ok: bool, message: string, path: null|string} */
    public static function importAsset(string $root, string $source): array
    {
        $source = trim($source);

        // Un chemin relatif est interprété depuis le projet, pas depuis le
        // répertoire courant de l'IDE.
        if ($source !== '' && !str_starts_with($source, '/') && !str_starts_with($source, '~')) {
            $source = rtrim($root, '/') . '/' . $source;
        }

        $source = self::normalizePath($source);

        if (!is_file($source) || !is_readable($source)) {
            return ['ok' => false, 'message' => 'Asset introuvable : ' . $source, 'path' => null];
        }

        $directory = rtrim($root, '/') . '/assets';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return ['ok' => false, 'message' => 'Dossier assets inaccessible.', 'path' => null];
        }

        $name = self::uniqueName($directory, basename($source));

        if (!copy($source, $directory . '/' . $name)) {
            return ['ok' => false, 'message' => 'Copie de l\'asset impossible.', 'path' => null];
        }

        self::refreshManifestAssets($root);

        return [
            'ok' => true,
            'message' => 'Asset ajouté : assets/' . $name,
            'path' => 'assets/' . $name,
        ];
    }

    /** @return array{ok: bool, message: string} */
    public static function removeAsset(string $root, string $name): array
    {
        $name = basename($name);
        $path = rtrim($root, '/') . '/assets/' . $name;

        if (!is_file($path)) {
            return ['ok' => false, 'message' => 'Asset introuvable : ' . $name];
        }

        if (!@unlink($path)) {
            return ['ok' => false, 'message' => 'Suppression impossible : ' . $name];
        }

        self::refreshManifestAssets($root);

        return ['ok' => true, 'message' => 'Asset supprimé : ' . $name];
    }

    /** Identifiant d'application proposé, toujours valide pour GTK/DBus. */
    public static function suggestAppId(string $name): string
    {
        $segment = str_replace('-', '', self::slug($name));

        if ($segment === '' || ctype_digit($segment[0])) {
            $segment = 'app' . $segment;
        }

        return 'com.example.' . $segment;
    }

    public static function slug(string $name): string
    {
        $map = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
            'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss',
            'À' => 'a', 'Á' => 'a', 'Â' => 'a', 'Ä' => 'a', 'Ã' => 'a', 'Å' => 'a',
            'Ç' => 'c', 'È' => 'e', 'É' => 'e', 'Ê' => 'e', 'Ë' => 'e',
            'Ì' => 'i', 'Í' => 'i', 'Î' => 'i', 'Ï' => 'i',
            'Ñ' => 'n', 'Ò' => 'o', 'Ó' => 'o', 'Ô' => 'o', 'Ö' => 'o', 'Õ' => 'o',
            'Ù' => 'u', 'Ú' => 'u', 'Û' => 'u', 'Ü' => 'u', 'Ý' => 'y',
            'Œ' => 'oe', 'Æ' => 'ae',
        ];

        $ascii = strtr($name, $map);
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)) ?? '';

        return trim($slug, '-') !== '' ? trim($slug, '-') : 'projet';
    }

    private static function writeLibrary(string $target, string $name): void
    {
        self::writeFile($target . '/src/Greeter.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            final class Greeter
            {
                public function greet(string $name): string
                {
                    return sprintf('Bonjour, %s !', $name);
                }
            }
            PHP);

        self::writeFile($target . '/src/main.php', <<<PHP
            <?php

            declare(strict_types=1);

            require dirname(__DIR__) . '/autoload.php';

            use App\\Greeter;

            echo (new Greeter())->greet('{$name}') . "\\n";
            PHP);

        self::writeFile($target . '/tests/test.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            require dirname(__DIR__) . '/autoload.php';

            use App\Greeter;

            $result = (new Greeter())->greet('Cyonima');

            if ($result !== 'Bonjour, Cyonima !') {
                fwrite(STDERR, "Échec : {$result}\n");
                exit(1);
            }

            echo $result . "\n";
            PHP);
    }

    private static function gtkTemplate(string $name, string $appId): string
    {
        $nameExport = var_export($name, true);

        return <<<PHP
            <?php

            declare(strict_types=1);

            require dirname(__DIR__) . '/autoload.php';

            use Cyonima\\Runtime\\AdwApplication;
            use Cyonima\\UI\\Box;
            use Cyonima\\UI\\Button;
            use Cyonima\\UI\\Label;
            use Cyonima\\UI\\Theme;

            \$app = new AdwApplication('{$appId}');
            \$window = \$app->window({$nameExport}, 'Application GTK4');
            Theme::apply(\$window);

            // Les assets du projet sont chargés par l'application elle-même.
            \$theme = dirname(__DIR__) . '/assets/theme.css';
            if (is_file(\$theme)) {
                \$window->addCss((string) file_get_contents(\$theme));
            }

            \$count = 0;
            \$counter = (new Label('0'))->cssClass('counter');
            \$button = (new Button('Incrémenter'))->onClicked(function () use (&\$count, \$counter): void {
                \$counter->text((string) ++\$count);
            });

            \$content = (new Box(1, 14))
                ->margin(28)
                ->append(
                    (new Label('Bienvenue dans ' . {$nameExport}))->cssClass('hero-title'),
                    (new Label('Projet GTK4 prêt à l\\'emploi.'))->cssClass('hero-subtitle'),
                    \$counter,
                    \$button,
                );

            \$window->content(\$content);
            \$window->present();
            \$app->run();
            PHP;
    }

    private static function consoleTemplate(string $name): string
    {
        $nameExport = var_export($name, true);

        return <<<PHP
            <?php

            declare(strict_types=1);

            require dirname(__DIR__) . '/autoload.php';

            \$name = {$nameExport};

            printf("%s\\n", \$name);
            printf("PHP %s · %s\\n", PHP_VERSION, date('Y-m-d H:i'));

            exit(0);
            PHP;
    }

    private static function autoloadTemplate(string $runtimeHome): string
    {
        $homeExport = var_export(rtrim($runtimeHome, '/'), true);

        return <<<PHP
            <?php

            declare(strict_types=1);

            // Runtime Cyonima (utile aux projets GTK), surchargeable par CYONIMA_HOME.
            \$cyonimaHome = getenv('CYONIMA_HOME') ?: {$homeExport};
            \$cyonimaAutoload = \$cyonimaHome . '/autoload.php';
            if (is_file(\$cyonimaAutoload)) {
                require \$cyonimaAutoload;
            }

            // Classes du projet : App\\Foo -> src/Foo.php
            spl_autoload_register(static function (string \$class): void {
                if (!str_starts_with(\$class, 'App\\\\')) {
                    return;
                }

                \$path = __DIR__ . '/src/' . str_replace('\\\\', '/', substr(\$class, 4)) . '.php';
                if (is_file(\$path)) {
                    require \$path;
                }
            });
            PHP;
    }

    private static function readme(
        string $name,
        string $template,
        string $appId,
        string $license,
        string $entry,
    ): string {
        $templates = self::templates();
        $licenseName = self::licenses()[$license];

        return <<<MD
            # {$name}

            Projet **{$templates[$template]['name']}** créé avec Cyonima.

            - Identifiant : `{$appId}`
            - Licence : {$licenseName}
            - Entrée : `{$entry}`

            ## Lancer

            ```bash
            php -d ffi.enable=1 {$entry}
            ```

            Si le runtime Cyonima n'est pas dans le chemin par défaut, définir
            `CYONIMA_HOME` vers l'installation de l'IDE.

            ## Structure

            ```
            .
            ├── .cyonima/project.json   manifeste (modèle, licence, assets)
            ├── assets/                 icône SVG, thème CSS, assets importés
            ├── src/                    code source
            ├── autoload.php            runtime + classes App\\
            ├── LICENSE
            └── README.md
            ```

            ## Assets

            - `assets/icon.svg` — icône du projet ;
            - `assets/theme.css` — styles chargés par l'application GTK.

            Ajouter un asset depuis Cyonima (page de création) ou en copiant un
            fichier dans `assets/`.
            MD;
    }

    private static function licenseText(string $spdx, string $name, string $year): string
    {
        return match ($spdx) {
            'MIT' => <<<TXT
                MIT License

                Copyright (c) {$year} {$name}

                Permission is hereby granted, free of charge, to any person obtaining a copy
                of this software and associated documentation files (the "Software"), to deal
                in the Software without restriction, including without limitation the rights
                to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
                copies of the Software, and to permit persons to whom the Software is
                furnished to do so, subject to the following conditions:

                The above copyright notice and this permission notice shall be included in all
                copies or substantial portions of the Software.

                THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
                IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
                FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
                AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
                LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
                OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
                SOFTWARE.
                TXT,
            'Apache-2.0' => <<<TXT
                Copyright {$year} {$name}

                Licensed under the Apache License, Version 2.0 (the "License");
                you may not use this file except in compliance with the License.
                You may obtain a copy of the License at

                    https://www.apache.org/licenses/LICENSE-2.0

                Unless required by applicable law or agreed to in writing, software
                distributed under the License is distributed on an "AS IS" BASIS,
                WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
                See the License for the specific language governing permissions and
                limitations under the License.
                TXT,
            'GPL-3.0' => <<<TXT
                Copyright (C) {$year} {$name}

                This program is free software: you can redistribute it and/or modify it under
                the terms of the GNU General Public License as published by the Free Software
                Foundation, either version 3 of the License, or (at your option) any later
                version.

                This program is distributed in the hope that it will be useful, but WITHOUT
                ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
                FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

                You should have received a copy of the GNU General Public License along with
                this program. If not, see <https://www.gnu.org/licenses/>.
                TXT,
            default => <<<TXT
                Copyright {$year} {$name}. Tous droits réservés.

                Ce logiciel est propriétaire. Toute reproduction, distribution ou
                modification, totale ou partielle, sans autorisation écrite est interdite.
                TXT,
        };
    }

    private static function iconSvg(string $name, string $accent): string
    {
        preg_match('/[\p{L}\p{N}]/u', $name, $match);
        $initial = strtoupper($match[0] ?? 'C');
        $initial = htmlspecialchars($initial, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128">
              <rect width="128" height="128" rx="24" fill="#1e1e1e"/>
              <rect x="8" y="8" width="112" height="112" rx="18" fill="{$accent}"/>
              <text x="64" y="84" font-family="DejaVu Sans, Cantarell, sans-serif" font-size="56"
                    font-weight="700" fill="#ffffff" text-anchor="middle">{$initial}</text>
            </svg>
            SVG;
    }

    /** @param array<string, mixed> $manifest */
    private static function writeManifest(string $root, array $manifest): void
    {
        $manifest['assets'] = array_map(
            static fn (array $asset): array => [
                'name' => $asset['name'],
                'path' => $asset['path'],
                'kind' => $asset['kind'],
                'size' => $asset['size'],
            ],
            self::listAssets($root),
        );

        $payload = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($payload === false) {
            throw new RuntimeException('Manifeste non sérialisable.');
        }

        self::writeFile(rtrim($root, '/') . '/' . self::MANIFEST, $payload . "\n");
    }

    private static function refreshManifestAssets(string $root): void
    {
        $manifest = self::manifest($root);
        if ($manifest === null) {
            return;
        }

        self::writeManifest($root, $manifest);
    }

    private static function assetKind(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'svg', 'png', 'jpg', 'jpeg', 'webp', 'gif' => 'image',
            'css' => 'style',
            'ttf', 'otf', 'woff', 'woff2' => 'font',
            'json', 'toml', 'yaml', 'yml' => 'data',
            default => 'file',
        };
    }

    private static function uniqueName(string $directory, string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '-', $name) ?: 'asset';
        $path = $directory . '/' . $name;

        if (!file_exists($path)) {
            return $name;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = $extension !== '' ? substr($name, 0, -(strlen($extension) + 1)) : $name;
        $suffix = $extension !== '' ? '.' . $extension : '';

        for ($index = 2; ; $index++) {
            $candidate = $base . '-' . $index . $suffix;
            if (!file_exists($directory . '/' . $candidate)) {
                return $candidate;
            }
        }
    }

    private static function normalizePath(string $path): string
    {
        $path = trim($path);

        if (str_starts_with($path, '~')) {
            $home = getenv('HOME');
            $path = ($home === false ? '' : rtrim($home, '/')) . substr($path, 1);
        }

        if (!str_starts_with($path, '/')) {
            $path = getcwd() . '/' . $path;
        }

        $real = realpath($path);

        return $real !== false ? $real : rtrim($path, '/');
    }

    private static function isDirectoryEmpty(string $directory): bool
    {
        $entries = scandir($directory);

        return $entries !== false && count($entries) <= 2;
    }

    private static function writeFile(string $path, string $content): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Dossier impossible à créer : ' . $directory);
        }

        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException('Écriture impossible : ' . $path);
        }
    }

    /** @return array{ok: false, message: string, path: null|string, manifest: null, warnings: list<string>} */
    private static function failure(string $message, ?string $path = null): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'path' => $path,
            'manifest' => null,
            'warnings' => [],
        ];
    }

    private const GITIGNORE = <<<'TXT'
        /vendor/
        .cyonima/*.local.json
        *.log
        *.tmp
        TXT;

    private const EDITORCONFIG = <<<'TXT'
        root = true

        [*]
        charset = utf-8
        end_of_line = lf
        insert_final_newline = true

        [*.php]
        indent_style = space
        indent_size = 4

        [*.{css,json,md}]
        indent_style = space
        indent_size = 2
        TXT;

    private const THEME_CSS = <<<'CSS'
        .hero-title {
            font-size: 24px;
            font-weight: 700;
            color: #ffffff;
        }

        .hero-subtitle {
            font-size: 13px;
            color: #9d9d9d;
        }

        .counter {
            font-size: 48px;
            font-weight: 700;
            color: #569cd6;
        }
        CSS;

    private const ASSETS_README = <<<'MD'
        # Assets du projet

        - `icon.svg` — icône générée à la création du projet.
        - `theme.css` — thème chargé par l'application GTK.

        Les assets importés depuis Cyonima sont copiés ici et référencés dans
        `.cyonima/project.json`.
        MD;
}
