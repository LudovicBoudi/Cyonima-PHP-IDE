<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw\Box as RawBox;
use Cyonima\Gtk\Raw\EventControllerKey as RawEventControllerKey;
use Cyonima\Gtk\Raw\Label as RawLabel;
use Cyonima\Gtk\Raw\ListBox as RawListBox;
use Cyonima\Gtk\Raw\ListBoxRow as RawListBoxRow;
use Cyonima\Gtk\Raw\Orientation;
use Cyonima\Gtk\Raw\Popover as RawPopover;
use Cyonima\Gtk\Raw\ScrolledWindow as RawScrolledWindow;

/**
 * Popover de sélection rapide réutilisable : champ de saisie, liste filtrée,
 * navigation clavier. Utilisé par la navigation de fichiers et la palette de
 * commandes.
 *
 * Les éléments sont des tableaux `label` + `detail` optionnel. Le filtre reçoit
 * les libellés et rend les libellés retenus dans l'ordre.
 */
final class QuickPick
{
    private RawPopover $popover;
    private Entry $entry;
    private RawListBox $list;

    /** @var list<RawListBoxRow> */
    private array $rows = [];

    /** @var list<array{label: string, detail?: string}> */
    private array $items = [];

    /** @var list<array{label: string, detail?: string}> */
    private array $matches = [];

    /** @var \Closure(list<string>, string): list<string> */
    private \Closure $matcher;

    /** @var \Closure(array{label: string, detail?: string}): void */
    private \Closure $onChoose;

    private int $selected = 0;
    private bool $shown = false;
    private bool $suppressFilter = false;

    public function __construct(
        AdwWindow|Window $window,
        string $placeholder,
        callable $matcher,
        callable $onChoose,
    ) {
        $this->matcher = $matcher(...);
        $this->onChoose = $onChoose(...);

        $this->entry = new Entry($placeholder);
        $this->entry->raw()->setSizeRequest(560, -1);
        $this->entry->onChanged(function (): void {
            if (!$this->suppressFilter) {
                $this->filter();
            }
        });
        $this->entry->onActivate(fn (): int => $this->chooseCurrent());

        $this->list = RawListBox::new();
        $this->list->setSelectionMode(1);
        $this->list->setSizeRequest(560, -1);

        $this->list->onRowActivated(function ($listBox, $row): void {
            $wrapped = RawListBoxRow::wrap($row, false);
            $this->choose($wrapped->getIndex());
        });

        $scrolled = RawScrolledWindow::new();
        $scrolled->setChild($this->list);
        $scrolled->setSizeRequest(560, 300);

        $content = RawBox::new(Orientation::VERTICAL, 6);
        $content->append($this->entry->raw());
        $content->append($scrolled);

        $this->popover = RawPopover::new();
        $this->popover->setHasArrow(false);
        $this->popover->setAutohide(true);
        $this->popover->setChild($content);
        $this->popover->setParent($window->raw());
        $this->popover->addCssClass('quick-open');
        $this->popover->onClosed(function (): void {
            $this->shown = false;
            $this->clearRows();
        });

        // Le popover est parenté à la fenêtre : le détacher avant destruction
        // évite un avertissement GTK à la fermeture.
        $window->raw()->onCloseRequest(function (): void {
            $this->popover->popdown();
            $this->popover->unparent();
        });

        $controller = RawEventControllerKey::new();
        $controller->onKeyPressed(fn ($controller, int $keyval, int $code, int $state): bool
            => $this->onKeyPressed($keyval));
        $this->entry->raw()->addController($controller);
    }

    /**
     * @param list<array{label: string, detail?: string}> $items
     */
    public function present(array $items, string $query = ''): void
    {
        $this->items = $items;
        $this->suppressFilter = true;
        $this->entry->setText($query);
        $this->suppressFilter = false;

        $this->filter();
        $this->popover->popup();
        $this->shown = true;
        $this->entry->raw()->grabFocus();
    }

    /**
     * @param list<array{label: string, detail?: string}> $items
     */
    public function setItems(array $items): static
    {
        $this->items = $items;
        $this->filter();
        return $this;
    }

    /** @return list<array{label: string, detail?: string}> */
    public function items(): array
    {
        return $this->items;
    }

    public function dismiss(): void
    {
        $this->popover->popdown();
        $this->shown = false;
        $this->clearRows();
        $this->matches = [];
        $this->suppressFilter = true;
        $this->entry->setText('');
        $this->suppressFilter = false;
    }

    public function visible(): bool
    {
        return $this->shown;
    }

    public function query(): string
    {
        return $this->entry->text();
    }

    public function setQuery(string $query): static
    {
        if ($this->entry->text() === $query) {
            $this->filter();
            return $this;
        }

        $this->entry->setText($query);
        return $this;
    }

    public function count(): int
    {
        return count($this->matches);
    }

    /** @return list<string> libellés filtrés, dans l'ordre affiché */
    public function labels(): array
    {
        return array_map(
            static fn (array $item): string => $item['label'],
            $this->matches,
        );
    }

    /** @return list<array{label: string, detail?: string}> */
    public function matches(): array
    {
        return $this->matches;
    }

    public function move(int $delta): void
    {
        if ($this->matches === []) {
            return;
        }

        $count = count($this->matches);
        $this->selected = (($this->selected + $delta) % $count + $count) % $count;

        if (isset($this->rows[$this->selected])) {
            $this->list->selectRow($this->rows[$this->selected]);
        }
    }

    public function chooseCurrent(): int
    {
        return $this->choose($this->selected);
    }

    public function choose(int $index): int
    {
        $item = $this->matches[$index] ?? null;

        if ($item === null) {
            return 0;
        }

        $this->dismiss();
        ($this->onChoose)($item);

        return 1;
    }

    public function filter(): int
    {
        $byLabel = [];

        foreach ($this->items as $item) {
            $byLabel[$item['label']] = $item;
        }

        $labels = ($this->matcher)(array_keys($byLabel), $this->entry->text());
        $this->matches = [];

        foreach ($labels as $label) {
            if (isset($byLabel[$label])) {
                $this->matches[] = $byLabel[$label];
            }
        }

        $this->buildRows();

        return count($this->matches);
    }

    private function buildRows(): void
    {
        $this->clearRows();
        $this->selected = 0;

        foreach ($this->matches as $index => $item) {
            $text = $item['label'];

            if (($item['detail'] ?? '') !== '') {
                $text .= '   ' . $item['detail'];
            }

            $label = RawLabel::new($text);
            $label->setXalign(0.0);
            $label->addCssClass('quick-open-result');

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

        if (isset($this->rows[0])) {
            $this->list->selectRow($this->rows[0]);
        }
    }

    private function clearRows(): void
    {
        foreach ($this->rows as $row) {
            $this->list->remove($row);
        }

        $this->rows = [];
    }

    private function onKeyPressed(int $keyval): bool
    {
        return match ($keyval) {
            0xff1b => $this->dismiss() ?? true, // Échap
            0xff52 => $this->move(-1) ?? true,  // Haut
            0xff54 => $this->move(1) ?? true,   // Bas
            default => false,
        };
    }
}
