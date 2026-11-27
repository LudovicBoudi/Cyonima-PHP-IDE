<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Template;

use Cyonima\Compiler\CompilerException;

/**
 * Compile un template déclaratif `.ui` en une classe PHP qui construit
 * l’arbre de widgets GTK via la façade objet native.
 */
final class TemplateCompiler
{
    private const COMMON_ATTRIBUTES = ['id', 'class', 'tooltip', 'sensitive'];

    private int $position = 0;
    private int $length = 0;
    private string $source = '';
    private int $widgetCounter = 0;
    private int $modelCounter = 0;
    private bool $windowSeen = false;
    private string $lastWidget = '';
    private ?int $contextLine = null;

    /** @var list<string> */
    private array $styleBlocks = [];

    /** @var list<array{key: string, handler: string, line: int}> */
    private array $shortcuts = [];

    /** @var list<array{name: string, phpType: string, docType: string, documented: bool}> */
    private array $parameters = [];

    public function __construct(
        private readonly string $className,
        private readonly ?string $path = null,
        private readonly ?\Closure $includeResolver = null,
    ) {
    }

    /**
     * Extrait les paramètres de `@params()` sans compiler le template.
     *
     * @return list<array{name: string, type: string}>
     */
    public static function parameterDeclarations(string $source): array
    {
        $compiler = new self('Fragment');
        $compiler->source = str_replace(["\r\n", "\r"], "\n", $source);
        $compiler->length = strlen($compiler->source);
        $compiler->position = 0;
        $compiler->parameters = [];
        $compiler->consumeParameters();

        $declarations = [];

        foreach ($compiler->parameters as $parameter) {
            $declarations[] = [
                'name' => $parameter['name'],
                'type' => $parameter['phpType'],
            ];
        }

        return $declarations;
    }

    public function compile(string $source): string
    {
        $this->source = str_replace(["\r\n", "\r"], "\n", $source);
        $this->length = strlen($this->source);
        $this->position = 0;
        $this->widgetCounter = 0;
        $this->modelCounter = 0;
        $this->windowSeen = false;
        $this->lastWidget = '';
        $this->contextLine = null;
        $this->styleBlocks = [];
        $this->shortcuts = [];
        $this->parameters = [];
        $this->consumeParameters();
        $nodes = $this->parseSequence(null);
        $this->skipWhitespace();

        if ($this->position < $this->length) {
            throw $this->error(
                'template : contenu inattendu à la position ' . $this->position,
            );
        }

        $isFragment = !$this->hasTopLevelWindow($nodes);
        $returnType = $isFragment ? 'Box' : 'Window';

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'namespace Cyonima\\Templates;';
        $lines[] = '';
        $lines[] = 'use Window;';
        $lines[] = 'use Box;';
        $lines[] = 'use Label;';
        $lines[] = 'use Button;';
        $lines[] = 'use Entry;';
        $lines[] = 'use CheckButton;';
        $lines[] = 'use ProgressBar;';
        $lines[] = 'use Separator;';
        $lines[] = 'use Popover;';
        $lines[] = 'use MenuButton;';
        $lines[] = 'use Menu;';
        $lines[] = 'use MenuBar;';
        $lines[] = 'use Image;';
        $lines[] = 'use ScrolledWindow;';
        $lines[] = 'use StringList;';
        $lines[] = 'use DropDown;';
        $lines[] = 'use ListBox;';
        $lines[] = 'use ListView;';
        $lines[] = '';
        $lines[] = 'final class ' . $this->className;
        $lines[] = '{';

        $docblock = [];
        $signature = [];

        foreach ($this->parameters as $parameter) {
            if ($parameter['documented']) {
                $docblock[] = '     * @param ' . $parameter['docType'] . ' $' . $parameter['name'];
            }

            $signature[] = $parameter['phpType'] . ' $' . $parameter['name'];
        }

        if ($docblock !== []) {
            $lines[] = '    /**';

            foreach ($docblock as $line) {
                $lines[] = $line;
            }

            $lines[] = '     * @return ' . $returnType;
            $lines[] = '     */';
        } else {
            $lines[] = '    /** @return ' . $returnType . ' */';
        }

        $lines[] = '    public static function build(' . implode(', ', $signature)
            . '): ' . $returnType;
        $lines[] = '    {';
        $body = [];

        if ($isFragment) {
            $body[] = '        $root = new Box(true, 0);';
        }

        $this->renderNodes($nodes, '$root', 2, $body, true);

        if ($isFragment) {
            if ($this->shortcuts !== []) {
                throw $this->error(
                    'template : <shortcut> exige une fenêtre',
                );
            }

            foreach ($this->styleBlocks as $css) {
                $body[] = '        ui_add_css(' . var_export($css, true) . ');';
            }

            $lines = array_merge($lines, $body);
            $lines[] = '        return $root;';
        } else {
            foreach ($this->shortcuts as $shortcut) {
                $body[] = '        // template-line ' . $shortcut['line'];
                $body[] = '        $window->shortcut('
                    . $shortcut['key'] . ', ' . $shortcut['handler'] . ');';
            }

            foreach ($this->styleBlocks as $css) {
                $body[] = '        $window->loadCss(' . var_export($css, true) . ');';
            }

            if (!$this->windowSeen) {
                throw $this->error(
                    'template : balise racine <window> manquante',
                );
            }

            $lines = array_merge($lines, $body);
            $lines[] = '        return $window;';
        }

        $lines[] = '    }';
        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    /** @param list<array<string, mixed>> $nodes */
    private function hasTopLevelWindow(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node['type'] === 'element' && $node['name'] === 'window') {
                return true;
            }
        }

        return false;
    }

    private function consumeParameters(): void
    {
        $this->skipWhitespace();

        if (!$this->startsWith('@params')) {
            return;
        }

        $this->position += strlen('@params');
        $this->skipWhitespace();

        if ($this->startsWith('(')) {
            $declaration = $this->readBalancedParentheses();
        } else {
            $end = strpos($this->source, "\n", $this->position);
            $line = $end === false
                ? substr($this->source, $this->position)
                : substr($this->source, $this->position, $end - $this->position);
            $this->position = $end === false ? $this->length : $end + 1;
            $declaration = trim($line);
        }

        if ($declaration === '') {
            return;
        }

        foreach ($this->splitTopLevel($declaration) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            $dollar = strrpos($part, '$');

            if ($dollar === false) {
                throw $this->error('template : paramètre sans nom : ' . $part);
            }

            $name = trim(substr($part, $dollar + 1));
            $type = trim(substr($part, 0, $dollar));

            if ($name === '' || $type === '') {
                throw $this->error('template : paramètre invalide : ' . $part);
            }

            $normalized = $this->normalizeParameterType($type);
            $this->parameters[] = [
                'name' => $name,
                'phpType' => $normalized['php'],
                'docType' => $normalized['doc'],
                'documented' => $normalized['doc'] !== $normalized['php'],
            ];
        }
    }

    /** @return array{php: string, doc: string} */
    private function normalizeParameterType(string $type): array
    {
        if (str_starts_with($type, '?')) {
            $inner = $this->normalizeParameterType(substr($type, 1));

            return [
                'php' => '?' . $inner['php'],
                'doc' => $inner['doc'],
            ];
        }

        if (preg_match('/^array<([^,<>]+)>$/', $type, $matches) === 1) {
            $element = $this->normalizeTypeName($matches[1]);

            return ['php' => 'array', 'doc' => 'array<' . $element . '>'];
        }

        if (preg_match('/^array<string,([^,<>]+)>$/', $type, $matches) === 1) {
            $element = $this->normalizeTypeName($matches[1]);

            return ['php' => 'array', 'doc' => 'array<string,' . $element . '>'];
        }

        if ($type === 'array') {
            throw $this->error(
                'template : type de tableau incomplet ; utilisez array<T> ou array<string,T>',
            );
        }

        $normalized = $this->normalizeTypeName($type);

        return ['php' => $normalized, 'doc' => $normalized];
    }

    private function normalizeTypeName(string $type): string
    {
        if (in_array($type, ['int', 'float', 'bool', 'string', 'void', 'callable'], true)) {
            return $type;
        }

        if (preg_match('/^callable\(.*\):(int|float|bool|string|void)$/', $type) === 1) {
            return $type;
        }

        if (str_starts_with($type, '\\')) {
            return $type;
        }

        return '\\' . $type;
    }

    /** @return array<string> */
    private function splitTopLevel(string $value): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;
        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            $character = $value[$index];

            if ($quote !== null) {
                $current .= $character;

                if ($character === $quote && ($index === 0 || $value[$index - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
                $current .= $character;
                continue;
            }

            if ($character === '<' || $character === '(') {
                $depth++;
                $current .= $character;
                continue;
            }

            if ($character === '>' || $character === ')') {
                $depth--;
                $current .= $character;
                continue;
            }

            if ($character === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $character;
        }

        $parts[] = $current;

        return $parts;
    }

    /** @return list<array<string, mixed>> */
    private function parseSequence(?string $closing): array
    {
        $nodes = [];

        while ($this->position < $this->length) {
            if ($closing !== null && $this->startsWith('</')) {
                $name = $this->readClosingTag();

                if ($name !== $closing) {
                    throw $this->error(
                        'template : balise fermante </' . $name . '> inattendue',
                    );
                }

                return $nodes;
            }

            if ($this->startsWith('{{--')) {
                $end = strpos($this->source, '--}}', $this->position + 4);

                if ($end === false) {
                    throw $this->error('template : commentaire non terminé');
                }

                $this->position = $end + 4;
                continue;
            }

            $character = $this->source[$this->position];

            if ($character === '<') {
                $nodes[] = $this->parseElement();
                continue;
            }

            if ($character === '@') {
                $nodes[] = $this->parseDirective();
                continue;
            }

            $textLine = $this->lineAt($this->position);
            $text = $this->readText();

            if (trim($text) !== '') {
                $nodes[] = [
                    'type' => 'text',
                    'value' => $text,
                    'line' => $textLine,
                ];
            }
        }

        if ($closing !== null) {
            throw $this->error(
                'template : balise <' . $closing . '> non fermée',
            );
        }

        return $nodes;
    }

    /** @return array<string, mixed> */
    private function parseElement(): array
    {
        $line = $this->lineAt($this->position);
        $this->consume('<');
        $this->skipWhitespace();
        $name = $this->readIdentifier();

        if ($name === '') {
            throw $this->error('template : nom de balise attendu');
        }

        $name = strtolower($name);
        $attributes = $this->readAttributes();
        $selfClosing = false;

        if ($this->startsWith('/>')) {
            $this->position += 2;
            $selfClosing = true;
        } else {
            $this->consume('>');
        }

        $children = [];
        $text = null;

        if (!$selfClosing) {
            if (in_array($name, ['label', 'button', 'style'], true)) {
                $text = $this->readRawTextUntilClosing($name);
            } elseif ($name === 'entry') {
                $this->skipWhitespace();
                $closing = $this->readClosingTag();

                if ($closing !== $name) {
                    throw $this->error(
                        'template : </' . $closing . '> inattendu pour <entry>',
                    );
                }
            } else {
                $children = $this->parseSequence($name);
            }
        }

        return [
            'type' => 'element',
            'name' => $name,
            'attributes' => $attributes,
            'children' => $children,
            'text' => $text,
            'line' => $line,
        ];
    }

    /** @return array<string, string> */
    private function readAttributes(): array
    {
        $attributes = [];

        while ($this->position < $this->length) {
            $this->skipWhitespace();

            if ($this->startsWith('/>') || $this->startsWith('>')) {
                return $attributes;
            }

            $name = $this->readAttributeName();

            if ($name === '') {
                throw $this->error('template : attribut invalide');
            }

            $this->skipWhitespace();
            $value = 'true';

            if ($this->startsWith('=')) {
                $this->position++;
                $this->skipWhitespace();
                $value = $this->readAttributeValue();
            }

            $key = strtolower(str_replace(['-', '_'], '', $name));
            $attributes[$key] = $value;
        }

        throw $this->error('template : balise non terminée');
    }

    private function readAttributeValue(): string
    {
        if ($this->position >= $this->length) {
            throw $this->error('template : valeur d’attribut attendue');
        }

        $quote = $this->source[$this->position];

        if ($quote === '"' || $quote === "'") {
            $this->position++;
            $start = $this->position;

            while ($this->position < $this->length) {
                if ($this->source[$this->position] === $quote) {
                    $value = substr($this->source, $start, $this->position - $start);
                    $this->position++;

                    return $value;
                }

                $this->position++;
            }

            throw $this->error('template : attribut non terminé');
        }

        $start = $this->position;

        while ($this->position < $this->length) {
            $character = $this->source[$this->position];

            if ($character === ' ' || $character === "\t" || $character === "\n"
                || $character === '>' || $character === '/') {
                break;
            }

            $this->position++;
        }

        return substr($this->source, $start, $this->position - $start);
    }

    private function readRawTextUntilClosing(string $tag): string
    {
        $closing = '</' . $tag . '>';
        $start = $this->position;

        while ($this->position < $this->length) {
            if ($this->startsWith($closing)) {
                $value = substr($this->source, $start, $this->position - $start);
                $this->position += strlen($closing);

                return $value;
            }

            if ($this->source[$this->position] === '<') {
                throw $this->error(
                    'template : balise imbriquée interdite dans <' . $tag . '>',
                );
            }

            $this->position++;
        }

        throw $this->error('template : balise <' . $tag . '> non fermée');
    }

    /** @return array<string, mixed> */
    private function parseDirective(): array
    {
        $line = $this->lineAt($this->position);
        $this->consume('@');
        $name = strtolower($this->readIdentifier());

        if ($name === '') {
            throw $this->error('template : directive attendue après @');
        }

        if (in_array($name, ['if', 'elseif', 'foreach'], true)) {
            $this->skipWhitespace();

            if (!$this->startsWith('(')) {
                throw $this->error('template : ( attendu après @' . $name);
            }

            $argument = $this->readBalancedParentheses();

            return [
                'type' => 'directive',
                'name' => $name,
                'argument' => $argument,
                'line' => $line,
            ];
        }

        if ($name === 'else' || $name === 'endif' || $name === 'endforeach') {
            return [
                'type' => 'directive',
                'name' => $name,
                'argument' => '',
                'line' => $line,
            ];
        }

        throw $this->error('template : directive inconnue @' . $name);
    }

    private function readBalancedParentheses(): string
    {
        $this->consume('(');
        $start = $this->position;
        $depth = 1;
        $quote = null;

        while ($this->position < $this->length) {
            $character = $this->source[$this->position];

            if ($quote !== null) {
                if ($character === $quote && $this->source[$this->position - 1] !== '\\') {
                    $quote = null;
                }

                $this->position++;
                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
                $this->position++;
                continue;
            }

            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;

                if ($depth === 0) {
                    $value = substr($this->source, $start, $this->position - $start);
                    $this->position++;

                    return trim($value);
                }
            }

            $this->position++;
        }

        throw $this->error('template : parenthèse non fermée');
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param list<string> $lines
     */
    private function renderNodes(
        array $nodes,
        string $parent,
        int $indent,
        array &$lines,
        bool $root = false,
    ): void {
        $prefix = str_repeat('    ', $indent);

        foreach ($nodes as $node) {
            $this->contextLine = isset($node['line']) ? (int) $node['line'] : null;

            if ($this->contextLine !== null) {
                $lines[] = '// template-line ' . $this->contextLine;
            }

            if ($node['type'] === 'text') {
                $text = trim((string) $node['value']);

                if ($text === '') {
                    continue;
                }

                $lines[] = $prefix . $this->widget('Label', $this->textExpression($text))
                    . ';';
                $lines[] = $prefix . $parent . '->append('
                    . $this->lastWidget . ');';
                continue;
            }

            if ($node['type'] === 'directive') {
                $lines[] = $prefix . $this->directiveCode($node);
                continue;
            }

            $name = (string) $node['name'];
            $attributes = $node['attributes'];

            if ($name === 'style') {
                $this->styleBlocks[] = (string) $node['text'];
                continue;
            }

            if ($name === 'shortcut') {
                if (!isset($attributes['key'])) {
                    throw $this->error(
                        'template : attribut "key" requis pour <shortcut>',
                    );
                }

                if (!isset($attributes['onactivate'])) {
                    throw $this->error(
                        'template : attribut "on-activate" requis pour <shortcut>',
                    );
                }

                $this->rejectUnknown($attributes, ['key', 'onactivate']);
                $this->shortcuts[] = [
                    'key' => $this->stringAttribute($attributes, 'key', ''),
                    'handler' => $this->requiredDynamic(
                        $attributes['onactivate'],
                        'on-activate',
                    ),
                    'line' => $this->contextLine ?? 1,
                ];
                continue;
            }

            if ($name === 'window') {
                if (!$root || $this->windowSeen) {
                    throw $this->error(
                        'template : une seule balise <window> racine est supportée',
                    );
                }

                $this->windowSeen = true;
                $title = $this->stringAttribute($attributes, 'title', 'Template');
                $width = $this->intAttribute($attributes, 'width', 640);
                $height = $this->intAttribute($attributes, 'height', 480);
                $this->rejectUnknown($attributes, [
                    'title', 'width', 'height', 'class', 'tooltip', 'sensitive',
                ]);
                $lines[] = $prefix . '$window = new Window('
                    . $title . ', ' . $width . ', ' . $height . ');';
                $this->applyCommon($attributes, '$window', $prefix, $lines);
                $lines[] = $prefix . '$root = new Box(true, 0);';
                $this->renderNodes($node['children'], '$root', $indent, $lines);
                $lines[] = $prefix . '$window->setChild($root);';
                continue;
            }

            if ($name === 'box') {
                $vertical = $this->boolAttribute($attributes, 'vertical', true);
                $spacing = $this->intAttribute($attributes, 'spacing', 0);
                $this->rejectUnknown($attributes, [
                    'vertical', 'spacing', 'class', 'tooltip', 'sensitive',
                ]);
                $variable = $this->widget(
                    'Box',
                    $vertical . ', ' . $spacing,
                );
                $lines[] = $prefix . $variable . ';';
                $this->applyCommon($attributes, $this->lastWidget, $prefix, $lines);
                $lines[] = $prefix . $parent . '->append(' . $this->lastWidget . ');';
                $this->renderNodes($node['children'], $this->lastWidget, $indent, $lines);
                continue;
            }

            if ($name === 'label') {
                $text = $this->elementText($node, $attributes);
                $this->rejectUnknown($attributes, [
                    'text', 'class', 'tooltip', 'sensitive',
                ]);
                $lines[] = $prefix . $this->widget('Label', $text) . ';';
                $this->applyCommon($attributes, $this->lastWidget, $prefix, $lines);
                $lines[] = $prefix . $parent . '->append(' . $this->lastWidget . ');';
                continue;
            }

            if ($name === 'button') {
                $text = $this->elementText($node, $attributes);
                $this->rejectUnknown($attributes, [
                    'text', 'onclick', 'class', 'tooltip', 'sensitive',
                ]);
                $variable = $this->widget('Button', $text);
                $lines[] = $prefix . $variable . ';';
                $this->applyCommon($attributes, $this->lastWidget, $prefix, $lines);
                $lines[] = $prefix . $parent . '->append(' . $this->lastWidget . ');';

                if (isset($attributes['onclick'])) {
                    $handler = $this->requiredDynamic($attributes['onclick'], 'on-click');
                    $lines[] = $prefix . $this->lastWidget . '->onClicked(' . $handler . ');';
                }
                continue;
            }

            if ($name === 'entry') {
                $placeholder = $this->stringAttribute($attributes, 'placeholder', '');
                $this->rejectUnknown($attributes, [
                    'placeholder', 'onactivate', 'class', 'tooltip', 'sensitive',
                ]);
                $variable = $this->widget('Entry', $placeholder);
                $lines[] = $prefix . $variable . ';';
                $this->applyCommon($attributes, $this->lastWidget, $prefix, $lines);
                $lines[] = $prefix . $parent . '->append(' . $this->lastWidget . ');';

                if (isset($attributes['onactivate'])) {
                    $handler = $this->requiredDynamic($attributes['onactivate'], 'on-activate');
                    $lines[] = $prefix . $this->lastWidget . '->onActivate(' . $handler . ');';
                }
                continue;
            }

            if ($name === 'check-button') {
                $text = isset($attributes['label'])
                    ? $this->textExpression($attributes['label'])
                    : $this->elementText($node, $attributes);
                $this->rejectUnknown($attributes, [
                    'label', 'text', 'active', 'ontoggled',
                    'class', 'tooltip', 'sensitive',
                ]);
                $active = $this->boolAttribute($attributes, 'active', false);
                $variable = $this->widget('CheckButton', $text . ', ' . $active);
                $lines[] = $prefix . $variable . ';';
                $this->applyCommon($attributes, $this->lastWidget, $prefix, $lines);
                $lines[] = $prefix . $parent . '->append(' . $this->lastWidget . ');';

                if (isset($attributes['ontoggled'])) {
                    $handler = $this->requiredDynamic($attributes['ontoggled'], 'on-toggled');
                    $lines[] = $prefix . $this->lastWidget . '->onToggled(' . $handler . ');';
                }
                continue;
            }

            if ($name === 'progress-bar') {
                $fraction = $this->floatAttribute($attributes, 'fraction', 0.0);
                $this->rejectUnknown($attributes, [
                    'fraction', 'class', 'tooltip', 'sensitive',
                ]);
                $variable = $this->widget('ProgressBar', '');
                $lines[] = $prefix . $variable . ';';
                $this->applyCommon($attributes, $this->lastWidget, $prefix, $lines);
                $lines[] = $prefix . $parent . '->append(' . $this->lastWidget . ');';
                $lines[] = $prefix . $this->lastWidget
                    . '->setFraction(' . $fraction . ');';
                continue;
            }

            if ($name === 'separator') {
                $vertical = $this->boolAttribute($attributes, 'vertical', false);
                $this->rejectUnknown($attributes, [
                    'vertical', 'class', 'tooltip', 'sensitive',
                ]);
                $variable = $this->widget('Separator', $vertical);
                $lines[] = $prefix . $variable . ';';
                $this->applyCommon($attributes, $this->lastWidget, $prefix, $lines);
                $lines[] = $prefix . $parent . '->append(' . $this->lastWidget . ');';
                continue;
            }

            if ($name === 'menu-bar') {
                $this->rejectUnknown($attributes, []);
                $variable = $this->widget('MenuBar', '');
                $lines[] = $prefix . $variable . ';';
                $bar = $this->lastWidget;
                $this->applyCommon($attributes, $bar, $prefix, $lines);
                $lines[] = $prefix . $parent . '->append(' . $bar . ');';
                $this->renderMenuBar($node['children'], $bar, $prefix, $lines);
                continue;
            }

            if ($name === 'menu-button') {
                $label = $this->stringAttribute($attributes, 'label', 'Menu');
                $vertical = $this->boolAttribute($attributes, 'vertical', true);
                $spacing = $this->intAttribute($attributes, 'spacing', 4);
                $this->rejectUnknown($attributes, ['label', 'vertical', 'spacing']);
                $variable = $this->widget('MenuButton', $label);
                $lines[] = $prefix . $variable . ';';
                $menu = $this->lastWidget;
                $this->applyCommon($attributes, $menu, $prefix, $lines);
                $lines[] = $prefix . $parent . '->append(' . $menu . ');';

                $popover = $this->widget('Popover', '');
                $lines[] = $prefix . $popover . ';';
                $popoverVariable = $this->lastWidget;

                $inner = $this->widget('Box', $vertical . ', ' . $spacing);
                $lines[] = $prefix . $inner . ';';
                $innerVariable = $this->lastWidget;

                $lines[] = $prefix . $popoverVariable . '->setChild('
                    . $innerVariable . ');';
                $lines[] = $prefix . $menu . '->setPopover(' . $popoverVariable . ');';
                $this->renderNodes($node['children'], $innerVariable, $indent, $lines);
                continue;
            }

            if ($name === 'image') {
                $src = $this->stringAttribute($attributes, 'src', '');
                $width = $this->intAttribute($attributes, 'width', -1);
                $height = $this->intAttribute($attributes, 'height', -1);
                $this->rejectUnknown($attributes, ['src', 'width', 'height']);
                $variable = $this->widget('Image', $src);
                $lines[] = $prefix . $variable . ';';
                $this->applyCommon($attributes, $this->lastWidget, $prefix, $lines);
                $lines[] = $prefix . $this->lastWidget
                    . '->setSize(' . $width . ', ' . $height . ');';
                $lines[] = $prefix . $parent . '->append(' . $this->lastWidget . ');';
                continue;
            }

            if ($name === 'scrolled') {
                $vertical = $this->boolAttribute($attributes, 'vertical', true);
                $spacing = $this->intAttribute($attributes, 'spacing', 0);
                $height = $this->intAttribute($attributes, 'height', -1);
                $this->rejectUnknown($attributes, ['vertical', 'spacing', 'height']);
                $variable = $this->widget('ScrolledWindow', '');
                $lines[] = $prefix . $variable . ';';
                $scrolled = $this->lastWidget;
                $this->applyCommon($attributes, $scrolled, $prefix, $lines);
                $lines[] = $prefix . $scrolled . '->setSize(-1, ' . $height . ');';
                $lines[] = $prefix . $parent . '->append(' . $scrolled . ');';
                $inner = $this->widget('Box', $vertical . ', ' . $spacing);
                $lines[] = $prefix . $inner . ';';
                $innerVariable = $this->lastWidget;
                $lines[] = $prefix . $scrolled . '->setChild(' . $innerVariable . ');';
                $this->renderNodes($node['children'], $innerVariable, $indent, $lines);
                continue;
            }

            if ($name === 'drop-down') {
                $sources = 0;

                foreach (['options', 'model', 'items'] as $source) {
                    if (isset($attributes[$source])) {
                        $sources++;
                    }
                }

                if ($sources !== 1) {
                    throw $this->error(
                        'template : <drop-down> exige soit "options", soit "model", soit "items"',
                    );
                }

                $selected = $this->intAttribute($attributes, 'selected', 0);
                $this->rejectUnknown(
                    $attributes,
                    ['options', 'model', 'items', 'selected', 'onchange'],
                );

                if (isset($attributes['items'])) {
                    $items = $this->requiredExpression($attributes['items'], 'items');
                    $this->widgetCounter++;
                    $this->lastWidget = '$w' . $this->widgetCounter;
                    $lines[] = $prefix . $this->lastWidget
                        . ' = DropDown::fromObjects(' . $items . ', ' . $selected . ');';
                } else {
                    if (isset($attributes['model'])) {
                        $modelVariable = $this->requiredExpression(
                            $attributes['model'],
                            'model',
                        );
                    } else {
                        $modelArguments = $this->optionList($attributes['options']);
                        $this->modelCounter++;
                        $modelVariable = '$m' . $this->modelCounter;
                        $lines[] = $prefix . $modelVariable
                            . ' = new StringList(' . $modelArguments . ');';
                    }

                    $lines[] = $prefix . $this->widget(
                        'DropDown',
                        $modelVariable . ', ' . $selected,
                    ) . ';';
                }

                $this->applyCommon($attributes, $this->lastWidget, $prefix, $lines);
                $lines[] = $prefix . $parent . '->append(' . $this->lastWidget . ');';

                if (isset($attributes['onchange'])) {
                    $handler = $this->requiredDynamic($attributes['onchange'], 'on-change');
                    $lines[] = $prefix . $this->lastWidget . '->onChanged(' . $handler . ');';
                }
                continue;
            }

            if ($name === 'list-box') {
                $selection = isset($attributes['selection'])
                    ? trim($attributes['selection'])
                    : 'single';

                if (!in_array($selection, ['single', 'none', 'multiple'], true)) {
                    throw $this->error(
                        'template : "selection" doit valoir single, none ou multiple',
                    );
                }

                $this->rejectUnknown($attributes, [
                    'selection', 'onrowactivated', 'items',
                ]);
                $variable = $this->widget('ListBox', '');
                $lines[] = $prefix . $variable . ';';
                $listBox = $this->lastWidget;
                $this->applyCommon($attributes, $listBox, $prefix, $lines);
                $lines[] = $prefix . $listBox . '->setSelectionMode('
                    . var_export($selection, true) . ');';
                $lines[] = $prefix . $parent . '->append(' . $listBox . ');';

                if (isset($attributes['items'])) {
                    $items = $this->requiredExpression($attributes['items'], 'items');
                    $lines[] = $prefix . $listBox . '->appendObjects(' . $items . ');';
                }

                $this->renderNodes($node['children'], $listBox, $indent, $lines);

                if (isset($attributes['onrowactivated'])) {
                    $handler = $this->requiredDynamic(
                        $attributes['onrowactivated'],
                        'on-row-activated',
                    );
                    $lines[] = $prefix . $listBox . '->onRowActivated(' . $handler . ');';
                }
                continue;
            }

            if ($name === 'list-view') {
                $sources = 0;

                foreach (['options', 'model', 'items'] as $source) {
                    if (isset($attributes[$source])) {
                        $sources++;
                    }
                }

                if ($sources !== 1) {
                    throw $this->error(
                        'template : <list-view> exige soit "options", soit "model", soit "items"',
                    );
                }

                $selected = $this->intAttribute($attributes, 'selected', 0);
                $this->rejectUnknown($attributes, [
                    'options', 'model', 'items', 'selected', 'onactivate',
                    'singleclick',
                ]);

                if (isset($attributes['items'])) {
                    $items = $this->requiredExpression($attributes['items'], 'items');
                    $this->widgetCounter++;
                    $this->lastWidget = '$w' . $this->widgetCounter;
                    $lines[] = $prefix . $this->lastWidget
                        . ' = ListView::fromObjects(' . $items . ', ' . $selected . ');';
                } else {
                    if (isset($attributes['model'])) {
                        $modelVariable = $this->requiredExpression(
                            $attributes['model'],
                            'model',
                        );
                    } else {
                        $modelArguments = $this->optionList($attributes['options']);
                        $this->modelCounter++;
                        $modelVariable = '$m' . $this->modelCounter;
                        $lines[] = $prefix . $modelVariable
                            . ' = new StringList(' . $modelArguments . ');';
                    }

                    $lines[] = $prefix . $this->widget(
                        'ListView',
                        $modelVariable . ', ' . $selected,
                    ) . ';';
                }

                $this->applyCommon($attributes, $this->lastWidget, $prefix, $lines);
                $lines[] = $prefix . $parent . '->append(' . $this->lastWidget . ');';

                if (isset($attributes['singleclick'])) {
                    $lines[] = $prefix . $this->lastWidget . '->setSingleClick('
                        . $this->boolAttribute($attributes, 'singleclick', true) . ');';
                }

                if (isset($attributes['onactivate'])) {
                    $handler = $this->requiredDynamic(
                        $attributes['onactivate'],
                        'on-activate',
                    );
                    $lines[] = $prefix . $this->lastWidget
                        . '->onActivated(' . $handler . ');';
                }
                continue;
            }

            if ($name === 'widget') {
                if (!isset($attributes['value'])) {
                    throw $this->error(
                        'template : attribut "value" requis pour <widget>',
                    );
                }

                $this->rejectUnknown($attributes, ['value']);
                $widget = $this->requiredExpression($attributes['value'], 'value');
                $lines[] = $prefix . $parent . '->append(' . $widget . ');';
                continue;
            }

            if ($name === 'include') {
                $this->renderInclude($attributes, $parent, $prefix, $lines);
                continue;
            }

            throw $this->error('template : balise inconnue <' . $name . '>');
        }
    }

    /** @param array<string, mixed> $node */
    private function elementText(array $node, array $attributes): string
    {
        if (isset($attributes['text'])) {
            return $this->textExpression($attributes['text']);
        }

        if ($node['text'] !== null) {
            return $this->textExpression((string) $node['text']);
        }

        throw $this->error(
            'template : texte manquant pour <' . $node['name'] . '>',
        );
    }

    /**
     * @param array<string, string> $attributes
     * @param list<string> $allowed
     */
    private function rejectUnknown(array $attributes, array $allowed): void
    {
        $allowed = array_merge($allowed, self::COMMON_ATTRIBUTES);

        foreach (array_keys($attributes) as $name) {
            if (!in_array($name, $allowed, true)) {
                throw $this->error('template : attribut inconnu "' . $name . '"');
            }
        }
    }

    /**
     * @param array<string, string> $attributes
     * @param list<string> $lines
     */
    private function appendItemEnabled(
        array $attributes,
        string $menu,
        int $position,
        string $prefix,
        array &$lines,
    ): void {
        if (!isset($attributes['enabled'])) {
            return;
        }

        $lines[] = $prefix . $menu . '->setEnabled(' . $position . ', '
            . $this->boolAttribute($attributes, 'enabled', true) . ');';
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param list<string> $lines
     */
    private function renderMenuBar(
        array $nodes,
        string $bar,
        string $prefix,
        array &$lines,
    ): void {
        foreach ($nodes as $node) {
            $this->contextLine = isset($node['line']) ? (int) $node['line'] : null;

            if ($this->contextLine !== null) {
                $lines[] = '// template-line ' . $this->contextLine;
            }

            if ($node['type'] === 'text') {
                if (trim((string) $node['value']) !== '') {
                    throw $this->error(
                        'template : texte inattendu dans <menu-bar>',
                    );
                }
                continue;
            }

            if ($node['type'] === 'directive') {
                throw $this->error(
                    'template : directive non supportée dans <menu-bar>',
                );
            }

            if ($node['name'] !== 'menu') {
                throw $this->error(
                    'template : balise <' . $node['name']
                    . '> inattendue dans <menu-bar>',
                );
            }

            $this->renderMenu($node, $bar, $prefix, $lines, true);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string> $lines
     */
    private function renderMenu(
        array $node,
        string $parentMenu,
        string $prefix,
        array &$lines,
        bool $barLevel,
    ): void {
        $attributes = $node['attributes'];

        if (!isset($attributes['label'])
            || trim((string) $attributes['label']) === '') {
            throw $this->error('template : label requis pour <menu>');
        }

        $this->rejectUnknown($attributes, ['label']);
        $label = $this->textExpression((string) $attributes['label']);
        $this->modelCounter++;
        $menu = '$m' . $this->modelCounter;
        $lines[] = $prefix . $menu . ' = new Menu();';
        $itemIndex = 0;

        foreach ($node['children'] as $child) {
            $this->contextLine = isset($child['line']) ? (int) $child['line'] : null;

            if ($this->contextLine !== null) {
                $lines[] = '// template-line ' . $this->contextLine;
            }

            if ($child['type'] === 'text') {
                if (trim((string) $child['value']) !== '') {
                    throw $this->error(
                        'template : texte inattendu dans <menu>',
                    );
                }
                continue;
            }

            if ($child['type'] === 'directive') {
                throw $this->error(
                    'template : directive non supportée dans <menu>',
                );
            }

            $name = (string) $child['name'];
            $childAttributes = $child['attributes'];

            if ($name === 'item') {
                if (!isset($childAttributes['label'])
                    || trim((string) $childAttributes['label']) === '') {
                    throw $this->error(
                        'template : label requis pour <item>',
                    );
                }

                $this->rejectUnknown($childAttributes, [
                    'label', 'onclick', 'ontoggle', 'onselect',
                    'checkable', 'checked', 'radio', 'value', 'enabled',
                    'accel', 'icon',
                ]);
                $itemLabel = $this->textExpression((string) $childAttributes['label']);
                $accelerator = $this->stringAttribute($childAttributes, 'accel', '');
                $icon = $this->stringAttribute($childAttributes, 'icon', '');
                $position = $itemIndex;
                $itemIndex++;

                if (isset($childAttributes['radio'])) {
                    if (!isset($childAttributes['value'])
                        || trim((string) $childAttributes['value']) === '') {
                        throw $this->error(
                            'template : value requis pour un <item radio>',
                        );
                    }

                    if (!isset($childAttributes['onselect'])) {
                        throw $this->error(
                            'template : on-select requis pour un <item radio>',
                        );
                    }

                    if (isset($childAttributes['onclick'])
                        || isset($childAttributes['ontoggle'])) {
                        throw $this->error(
                            'template : on-click et on-toggle sont interdits pour un <item radio>',
                        );
                    }

                    $group = $this->textExpression((string) $childAttributes['radio']);
                    $value = $this->textExpression((string) $childAttributes['value']);
                    $checked = $this->boolAttribute($childAttributes, 'checked', false);
                    $handler = $this->requiredDynamic(
                        $childAttributes['onselect'],
                        'on-select',
                    );
                    $lines[] = $prefix . $menu . '->addRadioItem('
                        . $group . ', ' . $itemLabel . ', ' . $value . ', '
                        . $checked . ', ' . $accelerator . ', ' . $icon . ', '
                        . $handler . ');';
                    $this->appendItemEnabled(
                        $childAttributes,
                        $menu,
                        $position,
                        $prefix,
                        $lines,
                    );
                    continue;
                }

                $checkable = $this->boolAttribute($childAttributes, 'checkable', false);

                if ($checkable === 'true') {
                    if (!isset($childAttributes['ontoggle'])) {
                        throw $this->error(
                            'template : on-toggle requis pour un <item checkable>',
                        );
                    }

                    if (isset($childAttributes['onclick'])) {
                        throw $this->error(
                            'template : on-click est interdit pour un <item checkable>',
                        );
                    }

                    $checked = $this->boolAttribute($childAttributes, 'checked', false);
                    $handler = $this->requiredDynamic(
                        $childAttributes['ontoggle'],
                        'on-toggle',
                    );
                    $lines[] = $prefix . $menu . '->addCheckItem('
                        . $itemLabel . ', ' . $checked . ', '
                        . $accelerator . ', ' . $icon . ', ' . $handler . ');';
                    $this->appendItemEnabled(
                        $childAttributes,
                        $menu,
                        $position,
                        $prefix,
                        $lines,
                    );
                    continue;
                }

                if (!isset($childAttributes['onclick'])) {
                    throw $this->error(
                        'template : on-click requis pour <item>',
                    );
                }

                $handler = $this->requiredDynamic(
                    $childAttributes['onclick'],
                    'on-click',
                );
                $lines[] = $prefix . $menu . '->addStyledItem('
                    . $itemLabel . ', ' . $accelerator . ', ' . $icon . ', '
                    . $handler . ');';
                $this->appendItemEnabled(
                    $childAttributes,
                    $menu,
                    $position,
                    $prefix,
                    $lines,
                );
                continue;
            }

            if ($name === 'separator') {
                $this->rejectUnknown($childAttributes, []);
                $lines[] = $prefix . $menu . '->addSeparator();';
                continue;
            }

            if ($name === 'menu') {
                $this->renderMenu($child, $menu, $prefix, $lines, false);
                continue;
            }

            throw $this->error(
                'template : balise <' . $name . '> inattendue dans <menu>',
            );
        }

        if ($barLevel) {
            $lines[] = $prefix . $parentMenu . '->add(' . $label . ', ' . $menu . ');';
        } else {
            $lines[] = $prefix . $parentMenu . '->addSubmenu('
                . $label . ', ' . $menu . ');';
        }
    }

    /**
     * @param array<string, string> $attributes
     * @param list<string> $lines
     */
    private function renderInclude(
        array $attributes,
        string $parent,
        string $prefix,
        array &$lines,
    ): void {
        $file = isset($attributes['file']) ? trim($attributes['file']) : '';

        if ($file === '') {
            throw $this->error(
                'template : attribut "file" requis pour <include>',
            );
        }

        if ($this->path === null || $this->includeResolver === null) {
            throw $this->error(
                'template : include indisponible sans chemin de fichier',
            );
        }

        $candidate = str_starts_with($file, '/')
            ? $file
            : dirname($this->path) . '/' . $file;
        $resolved = realpath($candidate) ?: $candidate;

        if (!is_file($resolved)) {
            throw $this->error('template : include introuvable : ' . $file);
        }

        $resolver = $this->includeResolver;

        try {
            $included = $resolver($resolved);
        } catch (CompilerException $exception) {
            throw $this->error($exception->getMessage());
        }

        if (($included['class'] ?? '') === $this->className) {
            throw $this->error(
                'template : include récursif de ' . $file,
            );
        }

        $arguments = [];
        $used = ['file' => true];

        foreach ($included['params'] ?? [] as $parameter) {
            $name = (string) $parameter['name'];

            if (!isset($attributes[$name])) {
                throw $this->error(
                    'template : paramètre "' . $name . '" manquant pour l’include '
                    . $file,
                );
            }

            $arguments[] = $this->includeArgument(
                (string) $parameter['type'],
                $attributes[$name],
                $name,
            );
            $used[$name] = true;
        }

        foreach (array_keys($attributes) as $name) {
            if (!isset($used[$name])) {
                throw $this->error(
                    'template : paramètre inconnu "' . $name . '" pour l’include '
                    . $file,
                );
            }
        }

        $this->widgetCounter++;
        $this->lastWidget = '$w' . $this->widgetCounter;
        $lines[] = $prefix . $this->lastWidget
            . ' = \\Cyonima\\Templates\\' . (string) $included['class']
            . '::build(' . implode(', ', $arguments) . ');';
        $lines[] = $prefix . $parent . '->append(' . $this->lastWidget . ');';
    }

    private function optionList(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw $this->error(
                'template : "options" ne peut pas être vide',
            );
        }

        if (preg_match('/^{{(.+)}}$/s', $trimmed, $matches) === 1) {
            return '(' . trim($matches[1]) . ')';
        }

        $items = [];

        foreach (explode(',', $trimmed) as $option) {
            $option = trim($option);

            if ($option === '') {
                throw $this->error(
                    'template : option vide dans <drop-down>',
                );
            }

            $items[] = var_export($option, true);
        }

        return '[' . implode(', ', $items) . ']';
    }

    private function includeArgument(string $type, string $value, string $name): string
    {        $trimmed = trim($value);

        if (preg_match('/^{{(.+)}}$/s', $trimmed, $matches) === 1) {
            return '(' . trim($matches[1]) . ')';
        }

        if ($type === 'string') {
            return var_export($value, true);
        }

        if ($type === 'bool') {
            if ($trimmed === 'true' || $trimmed === '1') {
                return 'true';
            }

            if ($trimmed === 'false' || $trimmed === '0') {
                return 'false';
            }
        }

        if ($type === 'int' && preg_match('/^-?[0-9]+$/', $trimmed) === 1) {
            return $trimmed;
        }

        if ($type === 'float'
            && preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $trimmed) === 1) {
            return $trimmed;
        }

        throw $this->error(
            'template : paramètre "' . $name . '" (' . $type
            . ') doit être {{ expression }}',
        );
    }

    /**
     * @param array<string, string> $attributes
     * @param list<string> $lines
     */
    private function applyCommon(
        array $attributes,
        string $widget,
        string $prefix,
        array &$lines,
    ): void {
        if (isset($attributes['id'])) {
            $lines[] = $prefix . $widget . '->setName('
                . $this->textExpression($attributes['id']) . ');';
        }

        if (isset($attributes['class'])) {
            foreach ($this->classNames($attributes['class']) as $class) {
                $lines[] = $prefix . $widget . '->addClass(' . $class . ');';
            }
        }

        if (isset($attributes['tooltip'])) {
            $lines[] = $prefix . $widget . '->setTooltip('
                . $this->textExpression($attributes['tooltip']) . ');';
        }

        if (isset($attributes['sensitive'])) {
            $lines[] = $prefix . $widget . '->setSensitive('
                . $this->boolAttribute($attributes, 'sensitive', true) . ');';
        }
    }

    /** @return list<string> */
    private function classNames(string $value): array
    {
        $trimmed = trim($value);

        if (preg_match('/^{{(.+)}}$/s', $trimmed, $matches) === 1) {
            return ['(' . trim($matches[1]) . ')'];
        }

        $names = [];

        foreach (preg_split('/\s+/', $trimmed) ?: [] as $name) {
            if ($name !== '') {
                $names[] = var_export($name, true);
            }
        }

        return $names;
    }

    /** @param array<string, string> $attributes */
    private function floatAttribute(array $attributes, string $name, float $default): string
    {
        if (!isset($attributes[$name])) {
            return var_export($default, true);
        }

        $value = trim($attributes[$name]);

        if (preg_match('/^{{(.+)}}$/s', $value, $matches) === 1) {
            return 'floatval(' . trim($matches[1]) . ')';
        }

        if (preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $value) === 1) {
            return $value;
        }

        throw $this->error('template : nombre attendu pour "' . $name . '"');
    }

    /** @param array<string, string> $attributes */
    private function stringAttribute(array $attributes, string $name, string $default): string
    {
        return isset($attributes[$name])
            ? $this->textExpression($attributes[$name])
            : var_export($default, true);
    }

    /** @param array<string, string> $attributes */
    private function intAttribute(array $attributes, string $name, int $default): string
    {
        if (!isset($attributes[$name])) {
            return (string) $default;
        }

        $value = trim($attributes[$name]);

        if (preg_match('/^{{(.+)}}$/s', $value, $matches) === 1) {
            return 'intval(' . trim($matches[1]) . ')';
        }

        if (preg_match('/^-?[0-9]+$/', $value) === 1) {
            return $value;
        }

        throw $this->error('template : entier attendu pour "' . $name . '"');
    }

    /** @param array<string, string> $attributes */
    private function boolAttribute(array $attributes, string $name, bool $default): string
    {
        if (!isset($attributes[$name])) {
            return $default ? 'true' : 'false';
        }

        $value = trim($attributes[$name]);

        if (preg_match('/^{{(.+)}}$/s', $value, $matches) === 1) {
            return '(' . trim($matches[1]) . ')';
        }

        if ($value === 'true' || $value === '1') {
            return 'true';
        }

        if ($value === 'false' || $value === '0') {
            return 'false';
        }

        throw $this->error('template : booléen attendu pour "' . $name . '"');
    }

    private function requiredExpression(string $value, string $attribute): string
    {
        if (preg_match('/^{{(.+)}}$/s', trim($value), $matches) !== 1) {
            throw $this->error(
                'template : "' . $attribute . '" doit être {{ expression }}',
            );
        }

        return '(' . trim($matches[1]) . ')';
    }

    private function requiredDynamic(string $value, string $attribute): string
    {
        if (preg_match('/^{{(.+)}}$/s', trim($value), $matches) !== 1) {
            throw $this->error(
                'template : "' . $attribute . '" doit être {{ $callable }}',
            );
        }

        return '(' . trim($matches[1]) . ')';
    }

    private function textExpression(string $text): string
    {
        $pattern = '/{{(.*?)}}/s';

        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return var_export($text, true);
        }

        $parts = [];
        $offset = 0;

        foreach ($matches[0] as $index => $match) {
            $position = $match[1];

            if ($position > $offset) {
                $parts[] = var_export(substr($text, $offset, $position - $offset), true);
            }

            $expression = trim($matches[1][$index][0]);
            $parts[] = '(' . $expression . ')';
            $offset = $position + strlen($match[0]);
        }

        if ($offset < strlen($text)) {
            $parts[] = var_export(substr($text, $offset), true);
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        return implode(' . ', $parts);
    }

    private function widget(string $class, string $arguments): string
    {
        $this->widgetCounter++;
        $this->lastWidget = '$w' . $this->widgetCounter;

        return $this->lastWidget . ' = new ' . $class . '(' . $arguments . ')';
    }

    /** @param array<string, mixed> $node */
    private function directiveCode(array $node): string
    {
        return match ($node['name']) {
            'if' => 'if (' . $node['argument'] . ') {',
            'elseif' => '} elseif (' . $node['argument'] . ') {',
            'else' => '} else {',
            'endif' => '}',
            'foreach' => 'foreach (' . $node['argument'] . ') {',
            'endforeach' => '}',
            default => throw $this->error(
                'template : directive non supportée @' . $node['name'],
            ),
        };
    }

    private function error(string $message): CompilerException
    {
        if (str_starts_with($message, 'template : ')) {
            $message = substr($message, strlen('template : '));
        }

        return new CompilerException(
            'template ligne ' . $this->diagnosticLine() . ' : ' . $message,
        );
    }

    private function diagnosticLine(): int
    {
        if ($this->contextLine !== null) {
            return $this->contextLine;
        }

        return $this->lineAt($this->position);
    }

    private function lineAt(int $position): int
    {
        $position = max(0, min($position, $this->length));

        return substr_count($this->source, "\n", 0, $position) + 1;
    }

    private function readText(): string
    {
        $start = $this->position;

        while ($this->position < $this->length) {
            $character = $this->source[$this->position];

            if ($character === '<' || $character === '@') {
                break;
            }

            $this->position++;
        }

        return substr($this->source, $start, $this->position - $start);
    }

    private function readIdentifier(): string
    {
        $start = $this->position;

        while ($this->position < $this->length) {
            $character = $this->source[$this->position];

            if (!ctype_alnum($character) && $character !== '_' && $character !== '-') {
                break;
            }

            $this->position++;
        }

        return substr($this->source, $start, $this->position - $start);
    }

    private function readAttributeName(): string
    {
        $start = $this->position;

        while ($this->position < $this->length) {
            $character = $this->source[$this->position];

            if (!ctype_alpha($character) && !ctype_digit($character)
                && $character !== '_' && $character !== '-' && $character !== ':') {
                break;
            }

            $this->position++;
        }

        return substr($this->source, $start, $this->position - $start);
    }

    private function readClosingTag(): string
    {
        $this->consume('</');
        $this->skipWhitespace();
        $name = strtolower($this->readIdentifier());
        $this->skipWhitespace();
        $this->consume('>');

        return $name;
    }

    private function skipWhitespace(): void
    {
        while ($this->position < $this->length
            && ctype_space($this->source[$this->position])) {
            $this->position++;
        }
    }

    private function startsWith(string $value): bool
    {
        return substr($this->source, $this->position, strlen($value)) === $value;
    }

    private function consume(string $value): void
    {
        if (!$this->startsWith($value)) {
            throw $this->error(
                'template : « ' . $value . ' » attendu à la position ' . $this->position,
            );
        }

        $this->position += strlen($value);
    }
}
