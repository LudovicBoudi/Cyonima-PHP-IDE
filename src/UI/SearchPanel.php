<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw\Box as RawBox;
use Cyonima\Gtk\Raw\Label as RawLabel;
use Cyonima\Gtk\Raw\ListBox as RawListBox;
use Cyonima\Gtk\Raw\ListBoxRow as RawListBoxRow;
use Cyonima\Gtk\Raw\Orientation;
use Cyonima\Runtime\ProjectSearch;

/**
 * Panneau de recherche projet : requête, résultats, ouverture à la ligne.
 */
final class SearchPanel extends Widget
{
    private readonly string $root;
    private Entry $entry;
    private Button $button;
    private RawLabel $countLabel;
    private RawListBox $list;

    /** @var list<RawListBoxRow> */
    private array $rows = [];

    /** @var list<array{path: string, line: int}> */
    private array $results = [];

    /** @var \Closure(string, int): void */
    private \Closure $onOpen;

    public function __construct(string $root, callable $onOpen)
    {
        parent::__construct(RawBox::new(Orientation::VERTICAL, 6));

        $this->root = realpath($root) ?: $root;
        $this->onOpen = $onOpen(...);

        $this->entry = (new Entry('Rechercher dans le projet'))
            ->onActivate(fn (): int => $this->runSearch());
        $this->button = (new Button('Rechercher'))
            ->onClicked(fn (): int => $this->runSearch());

        $toolbar = (new Box(Orientation::HORIZONTAL, 4))
            ->cssClass('search-toolbar')
            ->append($this->entry->expand(), $this->button);

        $this->countLabel = RawLabel::new('0 résultat');
        $this->countLabel->setXalign(0.0);
        $this->countLabel->addCssClass('search-count');

        $this->list = RawListBox::new();
        $this->list->setSelectionMode(1);
        $this->list->setActivateOnSingleClick(true);
        $this->list->onRowActivated(function ($listBox, $row): void {
            $wrapped = RawListBoxRow::wrap($row, false);
            $this->activateIndex($wrapped->getIndex());
        });

        $this->raw()->append($toolbar->raw());
        $this->raw()->append($this->countLabel);
        $this->raw()->append($this->list);
    }

    public function raw(): RawBox
    {
        /** @var RawBox $raw */
        $raw = $this->raw;
        return $raw;
    }

    public function query(): string
    {
        return $this->entry->text();
    }

    public function resultCount(): int
    {
        return count($this->results);
    }

    public function focus(): void
    {
        $this->entry->raw()->grabFocus();
    }

    public function refresh(): void
    {
        $this->search($this->entry->text());
    }

    public function search(string $query): int
    {
        foreach ($this->rows as $row) {
            $this->list->remove($row);
        }

        $this->rows = [];
        $this->results = [];

        $matches = ProjectSearch::search($this->root, $query);

        foreach ($matches as $match) {
            $label = RawLabel::new(sprintf(
                '%s:%d  %s',
                $match['path'],
                $match['line'],
                $match['text'],
            ));
            $label->setXalign(0.0);
            $label->addCssClass('search-result');

            $row = RawListBoxRow::new();
            $row->setChild($label);
            $row->setActivatable(true);
            $this->list->append($row);

            $this->rows[] = $row;
            $this->results[] = [
                'path' => $match['path'],
                'line' => $match['line'],
            ];
        }

        $count = count($this->results);
        $this->countLabel->setText($count === 1 ? '1 résultat' : $count . ' résultats');

        return $count;
    }

    /** Simule l'activation d'un résultat. */
    public function activateRow(int $index): void
    {
        if (isset($this->rows[$index])) {
            $this->list->emit('row-activated', $this->rows[$index]->handle());
        }
    }

    private function runSearch(): int
    {
        return $this->search($this->entry->text());
    }

    private function activateIndex(int $index): void
    {
        $result = $this->results[$index] ?? null;

        if ($result === null) {
            return;
        }

        ($this->onOpen)($this->root . '/' . $result['path'], $result['line']);
    }
}
