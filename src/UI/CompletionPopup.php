<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw\Label as RawLabel;
use Cyonima\Gtk\Raw\ListBox as RawListBox;
use Cyonima\Gtk\Raw\ListBoxRow as RawListBoxRow;
use Cyonima\Gtk\Raw\Popover as RawPopover;
use Cyonima\Runtime\GtkRect;
use FFI;

/**
 * Popover de complétion : liste de propositions, navigation clavier et
 * sélection. Le choix remonte l'élément complet au callback fourni.
 */
final class CompletionPopup
{
    private RawPopover $popover;
    private RawListBox $list;

    /** @var list<RawListBoxRow> */
    private array $rows = [];

    /** @var list<array{label: string, insertText: ?string, detail: string, kind: int}> */
    private array $items = [];

    /** @var null|\Closure(array): void */
    private ?\Closure $onSelect = null;

    private int $selected = 0;
    private bool $shown = false;

    public function __construct(Widget $anchor)
    {
        $this->popover = RawPopover::new();
        $this->popover->setHasArrow(true);
        $this->popover->setAutohide(true);

        $this->list = RawListBox::new();
        $this->list->setSelectionMode(1); // GTK_SELECTION_SINGLE
        $this->popover->setChild($this->list);
        $this->popover->setParent($anchor->raw());
    }

    /**
     * @param list<array{label: string, insertText: ?string, detail: string, kind: int}> $items
     * @param callable(array): void $onSelect
     * @param null|array{x: int, y: int, width: int, height: int} $caret rectangle du curseur
     */
    public function show(array $items, callable $onSelect, ?array $caret = null): void
    {
        $this->hide();

        if ($caret !== null) {
            $rect = GtkRect::create(
                $caret['x'],
                $caret['y'],
                max(1, $caret['width']),
                max(1, $caret['height']),
            );
            $this->popover->setPointingTo(FFI::addr($rect));
        }

        $this->items = $items;
        $this->onSelect = $onSelect(...);
        $this->selected = 0;

        foreach ($items as $index => $item) {
            $text = $item['label'];
            if ($item['detail'] !== '') {
                $text .= '   ' . $item['detail'];
            }

            $label = RawLabel::new($text);
            $label->setXalign(0.0);

            $row = RawListBoxRow::new();
            $row->setChild($label);
            $row->setActivatable(true);
            $row->onActivate(function () use ($index): void {
                $this->choose($index);
            });

            $this->list->append($row);
            $this->rows[] = $row;
        }

        if (isset($this->rows[0])) {
            $this->list->selectRow($this->rows[0]);
        }

        $this->popover->popup();
        $this->shown = true;
    }

    public function hide(): void
    {
        $this->popover->popdown();

        foreach ($this->rows as $row) {
            $this->list->remove($row);
        }

        $this->rows = [];
        $this->items = [];
        $this->onSelect = null;
        $this->shown = false;
    }

    public function visible(): bool
    {
        return $this->shown;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function move(int $delta): void
    {
        if ($this->items === []) {
            return;
        }

        $count = count($this->items);
        $this->selected = (($this->selected + $delta) % $count + $count) % $count;

        if (isset($this->rows[$this->selected])) {
            $this->list->selectRow($this->rows[$this->selected]);
        }
    }

    public function chooseCurrent(): void
    {
        $this->choose($this->selected);
    }

    public function choose(int $index): void
    {
        $item = $this->items[$index] ?? null;
        $callback = $this->onSelect;

        $this->hide();

        if ($item !== null && $callback !== null) {
            $callback($item);
        }
    }
}
