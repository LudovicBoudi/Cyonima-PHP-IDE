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
    private int $position = 0;
    private int $length = 0;
    private string $source = '';
    private int $widgetCounter = 0;
    private bool $windowSeen = false;
    private string $lastWidget = '';

    /** @var list<array{name: string, phpType: string, docType: string, documented: bool}> */
    private array $parameters = [];

    public function __construct(
        private readonly string $className,
    ) {
    }

    public function compile(string $source): string
    {
        $this->source = str_replace(["\r\n", "\r"], "\n", $source);
        $this->length = strlen($this->source);
        $this->position = 0;
        $this->widgetCounter = 0;
        $this->windowSeen = false;
        $this->lastWidget = '';
        $this->parameters = [];
        $this->consumeParameters();
        $nodes = $this->parseSequence(null);
        $this->skipWhitespace();

        if ($this->position < $this->length) {
            throw new CompilerException(
                'template : contenu inattendu à la position ' . $this->position,
            );
        }

        $lines = [];
        $lines[] = '<?php';$lines[] = '';
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

            $lines[] = '     * @return Window';
            $lines[] = '     */';
        } else {
            $lines[] = '    /** @return Window */';
        }

        $lines[] = '    public static function build(' . implode(', ', $signature) . '): Window';
        $lines[] = '    {';
        $body = [];
        $this->renderNodes($nodes, '$root', 2, $body, true);

        if (!$this->windowSeen) {
            throw new CompilerException(
                'template : balise racine <window> manquante',
            );
        }

        $lines = array_merge($lines, $body);
        $lines[] = '        return $window;';
        $lines[] = '    }';
        $lines[] = '}';

        return implode("\n", $lines) . "\n";
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
                throw new CompilerException('template : paramètre sans nom : ' . $part);
            }

            $name = trim(substr($part, $dollar + 1));
            $type = trim(substr($part, 0, $dollar));

            if ($name === '' || $type === '') {
                throw new CompilerException('template : paramètre invalide : ' . $part);
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
            throw new CompilerException(
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
                    throw new CompilerException(
                        'template : balise fermante </' . $name . '> inattendue',
                    );
                }

                return $nodes;
            }

            if ($this->startsWith('{{--')) {
                $end = strpos($this->source, '--}}', $this->position + 4);

                if ($end === false) {
                    throw new CompilerException('template : commentaire non terminé');
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

            $text = $this->readText();

            if (trim($text) !== '') {
                $nodes[] = ['type' => 'text', 'value' => $text];
            }
        }

        if ($closing !== null) {
            throw new CompilerException(
                'template : balise <' . $closing . '> non fermée',
            );
        }

        return $nodes;
    }

    /** @return array<string, mixed> */
    private function parseElement(): array
    {
        $this->consume('<');
        $this->skipWhitespace();
        $name = $this->readIdentifier();

        if ($name === '') {
            throw new CompilerException('template : nom de balise attendu');
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
            if (in_array($name, ['label', 'button'], true)) {
                $text = $this->readRawTextUntilClosing($name);
            } elseif ($name === 'entry') {
                $this->skipWhitespace();
                $closing = $this->readClosingTag();

                if ($closing !== $name) {
                    throw new CompilerException(
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
                throw new CompilerException('template : attribut invalide');
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

        throw new CompilerException('template : balise non terminée');
    }

    private function readAttributeValue(): string
    {
        if ($this->position >= $this->length) {
            throw new CompilerException('template : valeur d’attribut attendue');
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

            throw new CompilerException('template : attribut non terminé');
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
                throw new CompilerException(
                    'template : balise imbriquée interdite dans <' . $tag . '>',
                );
            }

            $this->position++;
        }

        throw new CompilerException('template : balise <' . $tag . '> non fermée');
    }

    /** @return array<string, mixed> */
    private function parseDirective(): array
    {
        $this->consume('@');
        $name = strtolower($this->readIdentifier());

        if ($name === '') {
            throw new CompilerException('template : directive attendue après @');
        }

        if (in_array($name, ['if', 'elseif', 'foreach'], true)) {
            $this->skipWhitespace();

            if (!$this->startsWith('(')) {
                throw new CompilerException('template : ( attendu après @' . $name);
            }

            $argument = $this->readBalancedParentheses();

            return ['type' => 'directive', 'name' => $name, 'argument' => $argument];
        }

        if ($name === 'else' || $name === 'endif' || $name === 'endforeach') {
            return ['type' => 'directive', 'name' => $name, 'argument' => ''];
        }

        throw new CompilerException('template : directive inconnue @' . $name);
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

        throw new CompilerException('template : parenthèse non fermée');
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

            if ($name === 'window') {
                if (!$root || $this->windowSeen) {
                    throw new CompilerException(
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

            throw new CompilerException('template : balise inconnue <' . $name . '>');
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

        throw new CompilerException(
            'template : texte manquant pour <' . $node['name'] . '>',
        );
    }

    /**
     * @param array<string, string> $attributes
     * @param list<string> $allowed
     */
    private function rejectUnknown(array $attributes, array $allowed): void
    {
        foreach (array_keys($attributes) as $name) {
            if (!in_array($name, $allowed, true)) {
                throw new CompilerException('template : attribut inconnu "' . $name . '"');
            }
        }
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

        throw new CompilerException('template : nombre attendu pour "' . $name . '"');
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

        throw new CompilerException('template : entier attendu pour "' . $name . '"');
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

        throw new CompilerException('template : booléen attendu pour "' . $name . '"');
    }

    private function requiredDynamic(string $value, string $attribute): string
    {
        if (preg_match('/^{{(.+)}}$/s', trim($value), $matches) !== 1) {
            throw new CompilerException(
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
            default => throw new CompilerException(
                'template : directive non supportée @' . $node['name'],
            ),
        };
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
            throw new CompilerException(
                'template : « ' . $value . ' » attendu à la position ' . $this->position,
            );
        }

        $this->position += strlen($value);
    }
}
