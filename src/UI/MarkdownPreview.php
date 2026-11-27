<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw\Box as RawBox;
use Cyonima\Gtk\Raw\ListBox as RawListBox;
use Cyonima\Gtk\Raw\ListBoxRow as RawListBoxRow;
use Cyonima\Gtk\Raw\Orientation;
use Cyonima\Gtk\Raw\ScrolledWindow as RawScrolledWindow;
use Cyonima\Runtime\Markdown;

/**
 * Aperçu Markdown natif : rendu Pango dans un label sélectionnable, avec
 * sommaire cliquable des titres.
 */
final class MarkdownPreview extends Widget
{
    private Label $label;
    private string $markup = '';

    /** @var list<int> map[ligne source] = ligne affichée */
    private array $lineMap = [];

    private int $renderedLines = 0;

    private Button $tocButton;
    private RawScrolledWindow $tocScroller;
    private RawListBox $tocList;

    /** @var list<RawListBoxRow> */
    private array $tocRows = [];

    /** @var list<array{level: int, text: string, line: int}> */
    private array $headings = [];

    private bool $tocVisible = false;

    /** @var (\Closure(int): void)|null */
    private ?\Closure $onNavigate = null;

    public function __construct()
    {
        parent::__construct(RawBox::new(Orientation::VERTICAL, 0));

        $this->cssClass('markdown-preview');

        $this->tocButton = (new Button('Sommaire'))
            ->cssClass('markdown-toc-toggle')
            ->onClicked(fn (): static => $this->toggleToc())
            ->visible(false);

        $this->tocList = RawListBox::new();
        $this->tocList->setSelectionMode(1);
        $this->tocList->setActivateOnSingleClick(true);
        $this->tocList->onRowActivated(function ($listBox, $row): void {
            $wrapped = RawListBoxRow::wrap($row, false);
            $this->activateTocIndex($wrapped->getIndex());
        });

        $this->tocScroller = RawScrolledWindow::new();
        $this->tocScroller->setChild($this->tocList);
        $this->tocScroller->setSizeRequest(-1, 140);
        $this->tocScroller->setVisible(false);

        $this->label = (new Label(''))
            ->cssClass('markdown-body')
            ->xalign(0.0);

        $this->label->raw()->setWrap(true);
        $this->label->raw()->setSelectable(true);

        $this->raw()->append($this->tocButton->raw());
        $this->raw()->append($this->tocScroller);
        $this->raw()->append($this->label->raw());
    }

    public function raw(): RawBox
    {
        /** @var RawBox $raw */
        $raw = $this->raw;
        return $raw;
    }

    /** @param null|callable(int): void $handler reçoit la ligne source du titre */
    public function setOnNavigate(?callable $handler): static
    {
        $this->onNavigate = $handler === null ? null : $handler(...);
        return $this;
    }

    public function render(string $markdown): static
    {
        $rendered = Markdown::toPangoMapped($markdown);
        $this->markup = $rendered['pango'];
        $this->lineMap = $rendered['map'];
        $this->renderedLines = $this->markup === '' ? 0 : count(explode("\n", $this->markup));
        $this->label->markup($this->markup !== '' ? $this->markup : '<i>Document vide</i>');

        $this->buildToc(Markdown::headings($markdown));

        return $this;
    }

    public function markup(): string
    {
        return $this->markup;
    }

    public function renderedLineCount(): int
    {
        return $this->renderedLines;
    }

    /** @return list<int> */
    public function lineMap(): array
    {
        return $this->lineMap;
    }

    /** @return list<array{level: int, text: string, line: int}> */
    public function headings(): array
    {
        return $this->headings;
    }

    public function tocCount(): int
    {
        return count($this->headings);
    }

    public function tocVisible(): bool
    {
        return $this->tocVisible;
    }

    public function toggleToc(): static
    {
        if ($this->headings === []) {
            return $this;
        }

        $this->tocVisible = !$this->tocVisible;
        $this->tocScroller->setVisible($this->tocVisible);

        if ($this->tocVisible && isset($this->tocRows[0])) {
            $this->tocList->selectRow($this->tocRows[0]);
        }

        return $this;
    }

    public function activateTocRow(int $index): bool
    {
        return $this->activateTocIndex($index);
    }

    public function previewLineForSourceLine(int $sourceLine): int
    {
        if ($this->lineMap === []) {
            return 0;
        }

        $sourceLine = max(0, min($sourceLine, count($this->lineMap) - 1));

        return max(0, min(
            $this->lineMap[$sourceLine],
            max(0, $this->renderedLines - 1),
        ));
    }

    public function sourceLineForPreviewLine(int $previewLine): int
    {
        if ($this->lineMap === []) {
            return 0;
        }

        $sourceLine = 0;

        foreach ($this->lineMap as $source => $rendered) {
            if ($rendered <= $previewLine) {
                $sourceLine = $source;
            } else {
                break;
            }
        }

        return $sourceLine;
    }

    /** @param list<array{level: int, text: string, line: int}> $headings */
    private function buildToc(array $headings): void
    {
        foreach ($this->tocRows as $row) {
            $this->tocList->remove($row);
        }

        $this->tocRows = [];
        $this->headings = $headings;

        foreach ($headings as $heading) {
            $label = (new Label(str_repeat('  ', max(0, $heading['level'] - 1)) . $heading['text']))
                ->cssClass('markdown-toc-row');
            $row = RawListBoxRow::new();
            $row->setChild($label->raw());
            $row->setActivatable(true);
            $this->tocList->append($row);
            $this->tocRows[] = $row;
        }

        if ($headings === []) {
            $this->tocVisible = false;
            $this->tocScroller->setVisible(false);
            $this->tocButton->visible(false);
            return;
        }

        $this->tocButton->label('Sommaire (' . count($headings) . ')');
        $this->tocButton->visible(true);
        $this->tocScroller->setVisible($this->tocVisible);
    }

    private function activateTocIndex(int $index): bool
    {
        $heading = $this->headings[$index] ?? null;

        if ($heading === null) {
            return false;
        }

        if ($this->onNavigate !== null) {
            ($this->onNavigate)($heading['line']);
        }

        return true;
    }
}
