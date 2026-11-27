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

    /** @var list<array{level: int, text: string, line: int, number: string}> */
    private array $headings = [];

    /** @var list<object{index: int, heading: array, children: list<object>}> */
    private array $tocTree = [];

    /** @var array<int, true> */
    private array $tocCollapsed = [];

    /** @var array<int, true> */
    private array $tocParents = [];

    /** @var list<int> index de titre pour chaque ligne affichée */
    private array $tocHeadingIndexes = [];

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

    /** @return list<array{level: int, text: string, line: int, number: string}> */
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

    public function toggleTocSection(int $headingIndex): bool
    {
        if (!isset($this->tocParents[$headingIndex])) {
            return false;
        }

        if (isset($this->tocCollapsed[$headingIndex])) {
            unset($this->tocCollapsed[$headingIndex]);
        } else {
            $this->tocCollapsed[$headingIndex] = true;
        }

        $this->renderTocRows();

        return true;
    }

    public function isTocCollapsed(int $headingIndex): bool
    {
        return isset($this->tocCollapsed[$headingIndex]);
    }

    /** @param list<array{level: int, text: string, line: int, number: string}> $headings */
    private function buildToc(array $headings): void
    {
        $this->headings = $headings;
        $this->tocTree = [];
        $this->tocCollapsed = [];
        $this->tocParents = [];

        $stack = [];

        foreach ($headings as $index => $heading) {
            $node = (object) [
                'index' => $index,
                'heading' => $heading,
                'children' => [],
            ];

            while ($stack !== [] && end($stack)->heading['level'] >= $heading['level']) {
                array_pop($stack);
            }

            if ($stack === []) {
                $this->tocTree[] = $node;
            } else {
                $stack[count($stack) - 1]->children[] = $node;
            }

            $stack[] = $node;
        }

        $this->renderTocRows();

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

    private function renderTocRows(): void
    {
        foreach ($this->tocRows as $row) {
            $this->tocList->remove($row);
        }

        $this->tocRows = [];
        $this->tocHeadingIndexes = [];

        foreach ($this->tocTree as $node) {
            $this->appendTocNode($node, 0);
        }

        if ($this->tocVisible && isset($this->tocRows[0])) {
            $this->tocList->selectRow($this->tocRows[0]);
        }
    }

    private function appendTocNode(object $node, int $depth): void
    {
        $heading = $node->heading;
        $collapsed = isset($this->tocCollapsed[$node->index]);
        $box = new Box(Orientation::HORIZONTAL, 2);
        $box->raw()->setMarginStart($depth * 12);

        if ($node->children !== []) {
            $this->tocParents[$node->index] = true;
            $toggle = (new IconButton(
                $collapsed ? 'pan-end-symbolic' : 'pan-down-symbolic',
                $collapsed ? 'Déplier' : 'Replier',
            ))
                ->cssClass('markdown-toc-toggle-icon')
                ->onClicked(fn (): bool => $this->toggleTocSection($node->index));
            $box->append($toggle);
        } else {
            $spacer = new Label('');
            $spacer->raw()->setSizeRequest(16, -1);
            $box->append($spacer);
        }

        $label = (new Label($heading['number'] . ' ' . $heading['text']))
            ->cssClass('markdown-toc-row');
        $box->append($label);

        $row = RawListBoxRow::new();
        $row->setChild($box->raw());
        $row->setActivatable(true);
        $this->tocList->append($row);
        $this->tocRows[] = $row;
        $this->tocHeadingIndexes[] = $node->index;

        if (!$collapsed) {
            foreach ($node->children as $child) {
                $this->appendTocNode($child, $depth + 1);
            }
        }
    }

    private function activateTocIndex(int $index): bool
    {
        $headingIndex = $this->tocHeadingIndexes[$index] ?? null;
        $heading = $headingIndex === null ? null : ($this->headings[$headingIndex] ?? null);

        if ($heading === null) {
            return false;
        }

        if ($this->onNavigate !== null) {
            ($this->onNavigate)($heading['line']);
        }

        return true;
    }
}
