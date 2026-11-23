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

    /** @var list<array{label: string, insertText: ?string, detail: string, kind: int}> liste complète */
    private array $allItems = [];

    private string $requestedPrefix = '';

    /** @var null|\Closure(array): void */
    private ?\Closure $onSelect = null;

    private int $selected = 0;
    private bool $shown = false;
    private bool $active = false;
    private ?array $caret = null;

    public function __construct(private readonly Widget $anchor)
    {
        $this->popover = RawPopover::new();
        $this->popover->setHasArrow(true);
        $this->popover->setAutohide(false);

        $this->list = RawListBox::new();
        $this->list->setSelectionMode(1); // GTK_SELECTION_SINGLE
        $this->list->setFocusable(false);
        $this->popover->setChild($this->list);
        $this->popover->setParent($anchor->raw());
        $this->popover->setFocusable(false);
    }

    /**
     * @param list<array{label: string, insertText: ?string, detail: string, kind: int}> $items
     * @param callable(array): void $onSelect
     * @param null|array{x: int, y: int, width: int, height: int} $caret rectangle du curseur
     */
    public function show(
        array $items,
        callable $onSelect,
        ?array $caret = null,
        string $prefix = '',
    ): void {
        $this->hide();

        $this->allItems = $items;
        $this->requestedPrefix = $prefix;
        $this->items = $items;
        $this->onSelect = $onSelect(...);
        $this->selected = 0;
        $this->caret = $caret;
        $this->active = true;

        $this->present();
    }

    /** Affiche (ou réaffiche) la liste courante, à la bonne taille. */
    public function present(): void
    {
        if ($this->items === [] || $this->onSelect === null) {
            return;
        }

        if ($this->caret !== null) {
            $rect = GtkRect::create(
                $this->caret['x'],
                $this->caret['y'],
                max(1, $this->caret['width']),
                max(1, $this->caret['height']),
            );
            $this->popover->setPointingTo(FFI::addr($rect));
        }

        $this->buildRows();

        if (isset($this->rows[0])) {
            $this->list->selectRow($this->rows[0]);
        }

        $this->popover->popup();
        $this->shown = true;

        // Le popover ne doit pas voler le clavier : la frappe continue dans
        // l'éditeur, ce qui permet le filtrage local.
        $this->anchor->grabFocus();
    }

    /**
     * Filtre la liste déjà affichée quand le préfixe s'allonge.
     *
     * @return bool true si le filtrage local suffit ; false s'il faut une
     *              nouvelle requête (préfixe raccourci, nouveau mot…)
     */
    public function filterByPrefix(string $prefix): bool
    {
        if (!$this->active) {
            return false;
        }

        if (!str_starts_with($prefix, $this->requestedPrefix)) {
            return false;
        }

        if ($prefix === $this->requestedPrefix) {
            return true;
        }

        $needle = strtolower($prefix);
        $filtered = array_values(array_filter(
            $this->allItems,
            static fn (array $item): bool => str_starts_with(strtolower($item['label']), $needle),
        ));

        if ($filtered === []) {
            $this->hide();
            return true;
        }

        $this->items = $filtered;
        $this->requestedPrefix = $prefix;
        $this->selected = 0;

        // Masqué pendant la frappe : le focus reste dans l'éditeur. Il sera
        // réaffiché avec la bonne taille après la dernière touche.
        $this->popover->popdown();
        $this->shown = false;

        return true;
    }

    public function hide(): void
    {
        $this->popover->popdown();

        foreach ($this->rows as $row) {
            $this->list->remove($row);
        }

        $this->rows = [];
        $this->items = [];
        $this->allItems = [];
        $this->requestedPrefix = '';
        $this->onSelect = null;
        $this->caret = null;
        $this->shown = false;
        $this->active = false;
    }

    private function buildRows(): void
    {
        foreach ($this->rows as $row) {
            $this->list->remove($row);
        }
        $this->rows = [];

        foreach ($this->items as $index => $item) {
            $text = $item['label'];
            if ($item['detail'] !== '') {
                $text .= '   ' . $item['detail'];
            }

            $label = RawLabel::new($text);
            $label->setXalign(0.0);

            $row = RawListBoxRow::new();
            $row->setChild($label);
            $row->setActivatable(true);
            $row->setFocusable(false);
            $row->onActivate(function () use ($index): void {
                $this->choose($index);
            });

            $this->list->append($row);
            $this->rows[] = $row;
        }
    }

    public function visible(): bool
    {
        return $this->shown;
    }

    /** Session de complétion en cours (même si la liste est momentanément masquée). */
    public function active(): bool
    {
        return $this->active;
    }

    /** @return array{0: int, 1: int} taille allouée du popover */
    public function size(): array
    {
        return [$this->popover->getWidth(), $this->popover->getHeight()];
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
