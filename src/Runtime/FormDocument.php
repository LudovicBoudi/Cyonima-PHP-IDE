<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

/**
 * Modèle d'un formulaire dessiné dans le concepteur.
 *
 * Chaque contrôle porte un nom PHP unique et peut exposer des événements.
 * La génération produit une classe de base regénérée (`MainFormBase`) et une
 * sous-classe utilisateur créée une seule fois (`MainForm`), où les actions
 * sont écrites.
 */
final class FormDocument
{
    /**
     * @var array<string, array{
     *     id: string, name: string, type: string, x: int, y: int,
     *     width: int, height: int, text: string, icon: string, events: list<string>
     * }>
     */
    private array $widgets = [];

    private int $nextId = 1;

    public function __construct(
        private string $name = 'main-form',
        private int $width = 720,
        private int $height = 480,
    ) {
        $this->width = max(200, $width);
        $this->height = max(160, $height);
    }

    /** @return array<string, array{name: string, defaultText: string, width: int, height: int}> */
    public static function types(): array
    {
        return [
            'label' => ['name' => 'Texte', 'defaultText' => 'Titre', 'width' => 180, 'height' => 28],
            'button' => ['name' => 'Bouton', 'defaultText' => 'Bouton', 'width' => 130, 'height' => 36],
            'entry' => ['name' => 'Champ de saisie', 'defaultText' => 'Votre texte…', 'width' => 240, 'height' => 38],
            'switch' => ['name' => 'Interrupteur', 'defaultText' => 'Option', 'width' => 240, 'height' => 52],
            'image' => ['name' => 'Image', 'defaultText' => 'assets/icon.svg', 'width' => 180, 'height' => 120],
            'video' => ['name' => 'Vidéo', 'defaultText' => 'assets/demo.mp4', 'width' => 320, 'height' => 180],
            'separator' => ['name' => 'Séparateur', 'defaultText' => '', 'width' => 240, 'height' => 2],
        ];
    }

    /** @return array<string, string> clé technique => libellé */
    public static function eventsForType(string $type): array
    {
        return match ($type) {
            'button' => ['click' => 'Sur clic', 'double-click' => 'Sur double-clic'],
            'entry' => ['changed' => 'Sur modification', 'activate' => 'Sur activation'],
            'switch' => ['changed' => 'Sur modification'],
            'image', 'video' => ['click' => 'Sur clic', 'double-click' => 'Sur double-clic'],
            'separator' => [],
            default => ['click' => 'Sur clic', 'double-click' => 'Sur double-clic'],
        };
    }

    public function name(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name) === '' ? 'main-form' : trim($name);
        return $this;
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    /** @return list<array{id: string, name: string, type: string, x: int, y: int, width: int, height: int, text: string, events: list<string>}> */
    public function widgets(): array
    {
        return array_values($this->widgets);
    }

    /** @return null|array{id: string, name: string, type: string, x: int, y: int, width: int, height: int, text: string, icon: string, events: list<string>} */
    public function get(string $id): ?array
    {
        return $this->widgets[$id] ?? null;
    }

    /** @return array{id: string, name: string, type: string, x: int, y: int, width: int, height: int, text: string, icon: string, events: list<string>} */
    public function add(string $type, int $x, int $y, ?string $text = null): array
    {
        $types = self::types();
        if (!isset($types[$type])) {
            throw new \InvalidArgumentException('Type de contrôle inconnu : ' . $type);
        }

        $definition = $types[$type];
        $id = 'w' . $this->nextId++;
        $item = [
            'id' => $id,
            'name' => $this->uniqueName($type),
            'type' => $type,
            'x' => max(0, min($this->width - 20, $x)),
            'y' => max(0, min($this->height - 20, $y)),
            'width' => $definition['width'],
            'height' => $definition['height'],
            'text' => $text ?? $definition['defaultText'],
            'icon' => '',
            'events' => [],
        ];

        $this->widgets[$id] = $item;

        return $item;
    }

    /**
     * @param array{name?: string, text?: string, icon?: string, x?: int, y?: int, width?: int, height?: int, events?: list<string>} $changes
     * @return null|array{id: string, name: string, type: string, x: int, y: int, width: int, height: int, text: string, icon: string, events: list<string>}
     */
    public function update(string $id, array $changes): ?array
    {
        if (!isset($this->widgets[$id])) {
            return null;
        }

        $item = $this->widgets[$id];

        if (isset($changes['name'])) {
            $candidate = self::normalizeName((string) $changes['name'], $item['type']);
            if (!$this->isNameTaken($candidate, $id)) {
                $item['name'] = $candidate;
            }
        }
        if (isset($changes['text'])) {
            $item['text'] = (string) $changes['text'];
        }
        if (isset($changes['icon'])) {
            $item['icon'] = (string) $changes['icon'];
        }
        if (isset($changes['x'])) {
            $item['x'] = max(0, min($this->width - 20, (int) $changes['x']));
        }
        if (isset($changes['y'])) {
            $item['y'] = max(0, min($this->height - 20, (int) $changes['y']));
        }
        if (isset($changes['width'])) {
            $item['width'] = max(40, min($this->width, (int) $changes['width']));
        }
        if (isset($changes['height'])) {
            $item['height'] = max(20, min($this->height, (int) $changes['height']));
        }
        if (isset($changes['events']) && is_array($changes['events'])) {
            $allowed = array_keys(self::eventsForType($item['type']));
            $selected = array_map('strval', $changes['events']);
            $item['events'] = array_values(array_filter(
                $allowed,
                static fn (string $event): bool => in_array($event, $selected, true),
            ));
        }

        $this->widgets[$id] = $item;

        return $item;
    }

    public function remove(string $id): bool
    {
        if (!isset($this->widgets[$id])) {
            return false;
        }

        unset($this->widgets[$id]);

        return true;
    }

    /** @return array{name: string, width: int, height: int, widgets: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'width' => $this->width,
            'height' => $this->height,
            'widgets' => $this->widgets(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $document = new self(
            is_string($data['name'] ?? null) ? $data['name'] : 'main-form',
            is_int($data['width'] ?? null) ? $data['width'] : 720,
            is_int($data['height'] ?? null) ? $data['height'] : 480,
        );

        $types = self::types();
        $maxId = 0;

        foreach (($data['widgets'] ?? []) as $row) {
            if (!is_array($row) || !is_string($row['type'] ?? null) || !isset($types[$row['type']])) {
                continue;
            }

            $id = is_string($row['id'] ?? null) && $row['id'] !== ''
                ? $row['id']
                : 'w' . (++$maxId);

            if (preg_match('/^w(\d+)$/', $id, $match)) {
                $maxId = max($maxId, (int) $match[1]);
            }

            $definition = $types[$row['type']];
            $name = self::normalizeName(
                is_string($row['name'] ?? null) ? $row['name'] : $row['type'],
                $row['type'],
            );
            if ($document->isNameTaken($name)) {
                $name .= '_2';
            }
            while ($document->isNameTaken($name)) {
                $name .= '_' . $document->nextId++;
            }

            $events = [];
            if (is_array($row['events'] ?? null)) {
                $allowed = array_keys(self::eventsForType($row['type']));
                $events = array_values(array_filter(
                    $allowed,
                    static fn (string $event): bool => in_array($event, $row['events'], true),
                ));
            }

            $document->widgets[$id] = [
                'id' => $id,
                'name' => $name,
                'type' => $row['type'],
                'x' => max(0, (int) ($row['x'] ?? 0)),
                'y' => max(0, (int) ($row['y'] ?? 0)),
                'width' => max(40, (int) ($row['width'] ?? $definition['width'])),
                'height' => max(20, (int) ($row['height'] ?? $definition['height'])),
                'text' => is_string($row['text'] ?? null) ? $row['text'] : $definition['defaultText'],
                'icon' => is_string($row['icon'] ?? null) ? $row['icon'] : '',
                'events' => $events,
            ];
        }

        $document->nextId = $maxId + 1;

        return $document;
    }

    /** @return list<array{name: string, slug: string, className: string, path: string}> */
    public static function listForms(string $root): array
    {
        $directory = rtrim($root, '/') . '/.cyonima/forms';
        $files = glob($directory . '/*.form.json') ?: [];
        $forms = [];

        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data) || !is_string($data['name'] ?? null)) {
                continue;
            }

            $form = new self($data['name']);
            $forms[] = [
                'name' => $data['name'],
                'slug' => self::slug($data['name']),
                'className' => $form->className(),
                'path' => $file,
            ];
        }

        usort($forms, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $forms;
    }

    public static function uniqueFormName(string $root, string $base = 'form'): string
    {
        $existing = [];
        foreach (self::listForms($root) as $form) {
            $existing[self::slug($form['name'])] = true;
        }

        $name = $base;
        $index = 2;
        while (isset($existing[self::slug($name)])
            || is_file(rtrim($root, '/') . '/.cyonima/forms/' . self::slug($name) . '.form.json')
        ) {
            $name = $base . '-' . $index++;
        }

        return $name;
    }

    public static function load(string $root, string $name = 'main-form'): ?self
    {
        $path = rtrim($root, '/') . '/.cyonima/forms/' . self::slug($name) . '.form.json';
        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? self::fromArray($data) : null;
    }

    /**
     * @return array{
     *     ok: bool, message: string, jsonPath: null|string,
     *     phpPath: null|string, basePath: null|string
     * }
     */
    public function save(string $root, ?string $namespace = null, ?string $className = null): array
    {
        $root = rtrim($root, '/');
        $slug = self::slug($this->name);
        $className = $className ?? $this->className();
        $baseClass = $className . 'Base';
        $namespace = $namespace ?? 'App\\Forms';

        $jsonPath = $root . '/.cyonima/forms/' . $slug . '.form.json';
        $basePath = $root . '/src/Forms/' . $baseClass . '.php';
        $phpPath = $root . '/src/Forms/' . $className . '.php';

        foreach ([$jsonPath, $basePath, $phpPath] as $path) {
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                return [
                    'ok' => false,
                    'message' => 'Dossier impossible à créer : ' . $directory,
                    'jsonPath' => null,
                    'phpPath' => null,
                    'basePath' => null,
                ];
            }
        }

        $json = json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $wroteBase = file_put_contents($basePath, $this->toBasePhp($namespace, $className)) !== false;

        if (is_file($phpPath)) {
            $existing = (string) file_get_contents($phpPath);
            $merged = $this->mergeUserPhp($existing);
            $createdUser = $merged === $existing || file_put_contents($phpPath, $merged) !== false;
        } else {
            $createdUser = file_put_contents($phpPath, $this->toUserPhp($namespace, $className)) !== false;
        }

        if ($json === false
            || file_put_contents($jsonPath, $json . "\n") === false
            || !$wroteBase
            || !$createdUser
        ) {
            return [
                'ok' => false,
                'message' => 'Écriture du formulaire impossible.',
                'jsonPath' => null,
                'phpPath' => null,
                'basePath' => null,
            ];
        }

        return [
            'ok' => true,
            'message' => 'Formulaire enregistré : ' . $className,
            'jsonPath' => $jsonPath,
            'phpPath' => $phpPath,
            'basePath' => $basePath,
        ];
    }

    public function className(): string
    {
        $parts = preg_split('/[-_]+/', self::slug($this->name)) ?: [];
        $class = '';

        foreach ($parts as $part) {
            $class .= ucfirst($part);
        }

        if ($class === '') {
            $class = 'MainForm';
        }
        if (ctype_digit($class[0])) {
            $class = 'Form' . $class;
        }

        return $class;
    }

    public function toPhp(string $namespace = 'App\\Forms', ?string $className = null): string
    {
        return $this->toBasePhp($namespace, $className);
    }

    public function toBasePhp(string $namespace = 'App\\Forms', ?string $className = null): string
    {
        $className = $className ?? $this->className();
        $baseClass = $className . 'Base';
        $namespace = trim($namespace, '\\');
        $slug = self::slug($this->name);

        $properties = '';
        $build = '';

        foreach ($this->widgets() as $widget) {
            $phpType = $this->phpType($widget['type']);
            $properties .= "\n    public {$phpType} \${$widget['name']};";

            $build .= "\n        \$this->{$widget['name']} = " . $this->widgetExpression($widget) . ';';
            $build .= "\n        \$form->put(\$this->{$widget['name']}, {$widget['x']}, {$widget['y']});";

            foreach ($widget['events'] as $event) {
                $method = $this->eventMethod($widget['name'], $event);
                $wiring = $this->eventWiring($widget['type'], $event, $method);
                if ($wiring !== '') {
                    $build .= "\n        \$this->{$widget['name']}{$wiring};";
                }
            }
        }

        $hooks = '';
        foreach ($this->widgets() as $widget) {
            foreach (array_keys(self::eventsForType($widget['type'])) as $event) {
                $method = $this->eventMethod($widget['name'], $event);
                $hooks .= "\n    protected function {$method}(): void\n    {\n    }\n";
            }
        }

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Cyonima\\Gtk\\Raw\\Orientation;
            use Cyonima\\UI\\Box;
            use Cyonima\\UI\\Button;
            use Cyonima\\UI\\Entry;
            use Cyonima\\UI\\Fixed;
            use Cyonima\\UI\\Image;
            use Cyonima\\UI\\Label;
            use Cyonima\\UI\\Separator;
            use Cyonima\\UI\\SwitchRow;
            use Cyonima\\UI\\Video;

            /**
             * Formulaire généré par Cyonima IDE.
             * Modèle : .cyonima/forms/{$slug}.form.json
             *
             * Cette classe est regénérée. Écrire les actions dans la sous-classe utilisateur.
             */
            class {$baseClass}
            {{$properties}

                public function build(): Fixed
                {
                    \$form = new Fixed({$this->width}, {$this->height});
            {$build}

                    return \$form;
                }
            {$hooks}
            }
            PHP;
    }

    public function toUserPhp(string $namespace = 'App\\Forms', ?string $className = null): string
    {
        $className = $className ?? $this->className();
        $baseClass = $className . 'Base';
        $namespace = trim($namespace, '\\');

        $methods = $this->userMethodStubs();

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            /**
             * Actions personnalisées du formulaire {$className}.
             * Ce fichier n'est créé qu'une seule fois par le concepteur.
             */
            final class {$className} extends {$baseClass}
            {{$methods}
            }
            PHP;
    }

    /**
     * Ajoute les méthodes manquantes à une sous-classe existante sans écraser
     * les actions déjà écrites par le développeur.
     */
    public function mergeUserPhp(string $contents): string
    {
        $missing = [];

        foreach ($this->widgets() as $widget) {
            foreach (array_keys(self::eventsForType($widget['type'])) as $event) {
                $method = $this->eventMethod($widget['name'], $event);
                if (!str_contains($contents, 'function ' . $method . '(')) {
                    $missing[] = $this->userMethodStub($widget['name'], $event);
                }
            }
        }

        if ($missing === []) {
            return $contents;
        }

        $position = strrpos($contents, '}');
        if ($position === false) {
            return $contents;
        }

        return substr($contents, 0, $position)
            . "\n" . implode("\n", $missing) . "\n"
            . substr($contents, $position);
    }

    private function userMethodStubs(): string
    {
        $methods = '';

        foreach ($this->widgets() as $widget) {
            foreach (array_keys(self::eventsForType($widget['type'])) as $event) {
                $methods .= $this->userMethodStub($widget['name'], $event);
            }
        }

        return $methods;
    }

    private function userMethodStub(string $name, string $event): string
    {
        $method = $this->eventMethod($name, $event);

        return "\n    protected function {$method}(): void\n    {\n"
            . "        // Action à écrire pour {$name} / {$event}.\n"
            . "    }\n";
    }

    /** @param array{type: string, name: string, text: string, icon: string, width: int, height: int} $widget */
    private function widgetExpression(array $widget): string
    {
        $text = var_export($widget['text'], true);
        $class = 'form-' . $widget['type'];
        $width = (int) $widget['width'];
        $height = (int) $widget['height'];

        $expression = match ($widget['type']) {
            'label' => "(new Label({$text}))->cssClass('{$class}')",
            'button' => $this->buttonExpression($widget, $class),
            'entry' => "(new Entry({$text}))->cssClass('{$class}')",
            'switch' => "(new SwitchRow({$text}, '', false))->cssClass('{$class}')",
            'image' => "(new Image(" . $this->pathExpression($widget['text']) . "))->cssClass('{$class}')",
            'video' => "(new Video(" . $this->pathExpression($widget['text']) . "))->cssClass('{$class}')",
            'separator' => "(new Separator())->cssClass('{$class}')",
            default => "new Label({$text})",
        };

        return $expression . "->sizeRequest({$width}, {$height})";
    }

    /** @param array{text: string, icon: string} $widget */
    private function buttonExpression(array $widget, string $class): string
    {
        if ($widget['icon'] === '') {
            return "(new Button(" . var_export($widget['text'], true) . "))->cssClass('{$class}')";
        }

        $content = "(new Box(Orientation::HORIZONTAL, 6))->append("
            . "(new Image(" . $this->pathExpression($widget['icon']) . "))->sizeRequest(20, 20)";

        if ($widget['text'] !== '') {
            $content .= ", (new Label(" . var_export($widget['text'], true) . "))";
        }

        $content .= ')';

        return "(new Button())->cssClass('{$class}')->child({$content})";
    }

    private function pathExpression(string $path): string
    {
        if ($path === '') {
            return "''";
        }

        if (str_starts_with($path, '/') || str_starts_with($path, '~')) {
            return var_export($path, true);
        }

        return "dirname(__DIR__, 2) . '/' . " . var_export($path, true);
    }

    private function phpType(string $type): string
    {
        return match ($type) {
            'button' => 'Button',
            'entry' => 'Entry',
            'switch' => 'SwitchRow',
            'image' => 'Image',
            'video' => 'Video',
            'separator' => 'Separator',
            default => 'Label',
        };
    }

    private function eventWiring(string $type, string $event, string $method): string
    {
        $callback = "fn () => \$this->{$method}()";

        return match ($event) {
            'click' => $type === 'button'
                ? "->onClicked({$callback})"
                : "->onClick({$callback})",
            'double-click' => "->onDoubleClick({$callback})",
            'changed' => "->onChanged({$callback})",
            'activate' => $type === 'entry' ? "->onActivate({$callback})" : '',
            default => '',
        };
    }

    private function eventMethod(string $name, string $event): string
    {
        $suffix = str_replace(' ', '', ucwords(str_replace('-', ' ', $event)));

        return 'on' . ucfirst($name) . $suffix;
    }

    public static function normalizeName(string $name, string $type = 'control'): string
    {
        $name = trim($name);
        $name = preg_replace('/[^A-Za-z0-9_]/', '_', $name) ?? '';

        if ($name === '' || !preg_match('/^[A-Za-z_]/', $name)) {
            $name = self::sanitizeType($type) . '_' . ltrim($name, '_');
        }

        return $name === '' ? $type : $name;
    }

    public static function slug(string $name): string
    {
        $ascii = strtr($name, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'œ' => 'oe', 'æ' => 'ae',
            'À' => 'a', 'Â' => 'a', 'Ä' => 'a', 'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
            'Î' => 'i', 'Ï' => 'i', 'Ô' => 'o', 'Ö' => 'o', 'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u',
            'Ç' => 'c', 'Œ' => 'oe', 'Æ' => 'ae',
        ]);

        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');

        return $slug === '' ? 'main-form' : $slug;
    }

    private function uniqueName(string $type): string
    {
        $base = self::sanitizeType($type);
        $index = 1;

        while ($this->isNameTaken($base . $index)) {
            $index++;
        }

        return $base . $index;
    }

    private function isNameTaken(string $name, ?string $exceptId = null): bool
    {
        foreach ($this->widgets as $id => $widget) {
            if ($id !== $exceptId && $widget['name'] === $name) {
                return true;
            }
        }

        return false;
    }

    private static function sanitizeType(string $type): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_]/', '_', $type) ?? 'control';

        return $clean === '' ? 'control' : $clean;
    }
}
