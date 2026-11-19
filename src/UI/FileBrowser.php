<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw\Box as RawBox;
use Cyonima\Gtk\Raw\Label as RawLabel;
use Cyonima\Gtk\Raw\ListBox as RawListBox;
use Cyonima\Gtk\Raw\ListBoxRow as RawListBoxRow;
use Cyonima\Gtk\Raw\Orientation;

/**
 * Explorateur de projet simple : une liste plate par répertoire, navigation
 * par activation. Évite GtkTreeView et ses iters, inutiles ici.
 */
final class FileBrowser extends Widget
{
    private readonly string $root;
    private string $directory;

    private RawListBox $list;
    private RawLabel $pathLabel;

    /** @var list<RawListBoxRow> garde les rows (et leurs handlers) en vie */
    private array $rows = [];

    /** @var \Closure(string): void */
    private \Closure $onOpen;

    public function __construct(string $directory, callable $onOpen)
    {
        $this->root = realpath($directory) ?: $directory;
        $this->directory = $this->root;
        $this->onOpen = $onOpen(...);

        parent::__construct(RawBox::new(Orientation::VERTICAL, 6));

        $this->pathLabel = RawLabel::new('.');
        $this->pathLabel->setXalign(0.0);
        $this->pathLabel->addCssClass('browser-path');

        $this->list = RawListBox::new();
        $this->list->setSelectionMode(1); // GTK_SELECTION_SINGLE

        $this->raw()->append($this->pathLabel);
        $this->raw()->append($this->list);

        $this->refresh();
    }

    public function raw(): RawBox
    {
        /** @var RawBox $raw */
        $raw = $this->raw;
        return $raw;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function entryCount(): int
    {
        return count($this->rows);
    }

    /** Ouvre un fichier comme le ferait un clic (utile aux tests/scénarios). */
    public function open(string $path): void
    {
        ($this->onOpen)($path);
    }

    public function refresh(): void
    {
        foreach ($this->rows as $row) {
            $this->list->remove($row);
        }
        $this->rows = [];

        $this->pathLabel->setText($this->displayPath());

        $entries = @scandir($this->directory);
        if ($entries === false) {
            return;
        }

        $directories = [];
        $files = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || str_starts_with($entry, '.')) {
                continue;
            }
            $path = $this->directory . '/' . $entry;
            if (is_dir($path)) {
                $directories[] = $entry;
            } else {
                $files[] = $entry;
            }
        }

        sort($directories);
        sort($files);

        if ($this->directory !== $this->root) {
            $this->addRow('..', dirname($this->directory), true, true);
        }
        foreach ($directories as $entry) {
            $this->addRow($entry, $this->directory . '/' . $entry, true);
        }
        foreach ($files as $entry) {
            $this->addRow($entry, $this->directory . '/' . $entry, false);
        }
    }

    private function addRow(string $entry, string $path, bool $isDirectory, bool $isParent = false): void
    {
        $label = RawLabel::new(($isDirectory ? '▸ ' : '  ') . $entry);
        $label->setXalign(0.0);

        $row = RawListBoxRow::new();
        $row->setChild($label);
        $row->setActivatable(true);

        $row->onActivate(function () use ($path, $isDirectory): void {
            if ($isDirectory) {
                $this->directory = $path;
                $this->refresh();
                return;
            }
            ($this->onOpen)($path);
        });

        $this->list->append($row);
        $this->rows[] = $row;
    }

    private function displayPath(): string
    {
        if ($this->directory === $this->root) {
            return '.';
        }

        $relative = substr($this->directory, strlen($this->root));

        return '.' . ($relative === '' ? '' : $relative);
    }
}
