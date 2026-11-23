<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw\Box as RawBox;
use Cyonima\Gtk\Raw\Label as RawLabel;
use Cyonima\Gtk\Raw\ListBox as RawListBox;
use Cyonima\Gtk\Raw\ListBoxRow as RawListBoxRow;
use Cyonima\Gtk\Raw\Orientation;
use Cyonima\Runtime\XdebugClient;

/**
 * Panneau de débogage : pile d'appels et variables locales.
 */
final class DebugPanel extends Widget
{
    private RawListBox $stackList;
    private RawListBox $variableList;

    /** @var list<RawListBoxRow> */
    private array $stackRows = [];

    /** @var list<RawListBoxRow> */
    private array $variableRows = [];

    public function __construct()
    {
        parent::__construct(RawBox::new(Orientation::VERTICAL, 6));

        $this->stackList = RawListBox::new();
        $this->stackList->setSelectionMode(1);
        $this->variableList = RawListBox::new();
        $this->variableList->setSelectionMode(0);

        $this->raw()->append($this->sectionTitle('Pile'));
        $this->raw()->append($this->stackList);
        $this->raw()->append($this->sectionTitle('Variables'));
        $this->raw()->append($this->variableList);
    }

    public function raw(): RawBox
    {
        /** @var RawBox $raw */
        $raw = $this->raw;
        return $raw;
    }

    /**
     * @param list<array{level: int, type: string, fileUri: ?string, line: ?int, where: string}> $frames
     */
    public function showStack(array $frames): void
    {
        $this->clearStack();

        foreach ($frames as $frame) {
            $file = $frame['fileUri'] !== null
                ? basename(XdebugClient::localPath($frame['fileUri']))
                : '?';

            $text = sprintf(
                '#%d  %s:%s   %s',
                $frame['level'],
                $file,
                $frame['line'] ?? '?',
                $frame['where'] !== '' ? $frame['where'] : '{main}',
            );

            $this->stackRows[] = $this->appendRow($this->stackList, $text, true);
        }
    }

    /**
     * @param list<array{name: string, fullname: string, type: string, value: string, children: bool, numChildren: int}> $variables
     */
    public function showVariables(array $variables): void
    {
        $this->clearVariables();

        foreach ($variables as $variable) {
            $value = $variable['value'];

            if ($variable['children']) {
                $value = sprintf(
                    '%s [%s, %d enfant(s)]',
                    $variable['type'] !== '' ? $variable['type'] : 'mixte',
                    $variable['type'],
                    $variable['numChildren'],
                );
            } elseif (strlen($value) > 80) {
                $value = substr($value, 0, 77) . '...';
            }

            $text = sprintf('%s = %s', $variable['name'], $value !== '' ? $value : '(vide)');
            $this->variableRows[] = $this->appendRow($this->variableList, $text, false);
        }
    }

    public function clear(): void
    {
        $this->clearStack();
        $this->clearVariables();
    }

    public function frameCount(): int
    {
        return count($this->stackRows);
    }

    public function variableCount(): int
    {
        return count($this->variableRows);
    }

    private function sectionTitle(string $text): RawLabel
    {
        $label = RawLabel::new($text);
        $label->setXalign(0.0);
        $label->addCssClass('debug-title');

        return $label;
    }

    private function appendRow(RawListBox $list, string $text, bool $activatable): RawListBoxRow
    {
        $label = RawLabel::new($text);
        $label->setXalign(0.0);

        $row = RawListBoxRow::new();
        $row->setChild($label);
        $row->setActivatable($activatable);

        $list->append($row);

        return $row;
    }

    private function clearStack(): void
    {
        foreach ($this->stackRows as $row) {
            $this->stackList->remove($row);
        }
        $this->stackRows = [];
    }

    private function clearVariables(): void
    {
        foreach ($this->variableRows as $row) {
            $this->variableList->remove($row);
        }
        $this->variableRows = [];
    }
}
