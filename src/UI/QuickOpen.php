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
use Cyonima\Runtime\ProjectSearch;
use Cyonima\Runtime\QuickOpenMatcher;

/**
 * Navigation rapide par nom de fichier (Ctrl+P) : popover centré, champ de
 * recherche, filtrage flou, sélection clavier ou souris.
 */
final class QuickOpen
{
    private const MAX_RESULTS = 50;

    private readonly string $root;
    private RawPopover $popover;
    private Entry $entry;
    private RawListBox $list;

    /** @var list<RawListBoxRow> */
    private array $rows = [];

    /** @var list<string> chemins relatifs complets */
    private array $paths = [];

    /** @var list<string> chemins filtrés, dans l'ordre d'affichage */
    private array $matches = [];

    /** @var \Closure(string): void */
    private \Closure $onOpen;

    private int $selected = 0;
    private bool $shown = false;
    private bool $suppressFilter = false;

    public function __construct(AdwWindow|Window $window, string $root, callable $onOpen)
    {
        $this->root = realpath($root) ?: $root;
        $this->onOpen = $onOpen(...);
        $this->paths = $this->candidates();

        $this->entry = new Entry('Aller au fichier…');
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

        $listRowActivated = function ($listBox, $row): void {
            $wrapped = RawListBoxRow::wrap($row, false);
            $this->choose($wrapped->getIndex());
        };
        $this->list->onRowActivated($listRowActivated);

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

    /** Recharge les fichiers du projet avant chaque ouverture. */
    public function refresh(): static
    {
        $this->paths = $this->candidates();
        return $this;
    }

    public function present(): void
    {
        $this->refresh();
        $this->suppressFilter = true;
        $this->entry->setText('');
        $this->suppressFilter = false;

        $this->filter();
        $this->popover->popup();
        $this->shown = true;
        $this->entry->raw()->grabFocus();
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

    /** @return list<string> */
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
        $path = $this->matches[$index] ?? null;

        if ($path === null) {
            return 0;
        }

        $this->dismiss();
        ($this->onOpen)($this->root . DIRECTORY_SEPARATOR . $path);

        return 1;
    }

    public function filter(): int
    {
        $this->matches = QuickOpenMatcher::match(
            $this->paths,
            $this->entry->text(),
            self::MAX_RESULTS,
        );

        $this->buildRows();

        return count($this->matches);
    }

    private function candidates(): array
    {
        $paths = [];

        foreach (ProjectSearch::files($this->root) as $path) {
            $relative = str_starts_with($path, $this->root . DIRECTORY_SEPARATOR)
                ? substr($path, strlen($this->root) + 1)
                : $path;
            $paths[] = $relative;
        }

        return $paths;
    }

    private function buildRows(): void
    {
        $this->clearRows();
        $this->selected = 0;

        foreach ($this->matches as $index => $path) {
            $label = RawLabel::new($path);
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
