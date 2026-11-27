<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw\Box as RawBox;
use Cyonima\Gtk\Raw\Label as RawLabel;
use Cyonima\Gtk\Raw\ListBox as RawListBox;
use Cyonima\Gtk\Raw\ListBoxRow as RawListBoxRow;
use Cyonima\Gtk\Raw\Orientation;
use Cyonima\Runtime\Diagnostics;

/**
 * Panneau Problèmes : diagnostics LSP triés par fichier et ligne, activables.
 */
final class ProblemsPanel extends Widget
{
    private RawListBox $list;
    private RawLabel $summaryLabel;

    /** @var list<RawListBoxRow> */
    private array $rows = [];

    /** @var list<array{path: string, line: int, column: int, severity: int, message: string, source: string}> */
    private array $entries = [];

    /** @var \Closure(string, int): void */
    private \Closure $onOpen;

    public function __construct(callable $onOpen)
    {
        parent::__construct(RawBox::new(Orientation::VERTICAL, 4));

        $this->onOpen = $onOpen(...);

        $this->summaryLabel = RawLabel::new('Aucun problème');
        $this->summaryLabel->setXalign(0.0);
        $this->summaryLabel->addCssClass('problems-summary');

        $this->list = RawListBox::new();
        $this->list->setSelectionMode(1);
        $this->list->setActivateOnSingleClick(true);
        $this->list->onRowActivated(function ($listBox, $row): void {
            $wrapped = RawListBoxRow::wrap($row, false);
            $this->activateIndex($wrapped->getIndex());
        });

        $this->raw()->append($this->summaryLabel);
        $this->raw()->append($this->list);
    }

    public function raw(): RawBox
    {
        /** @var RawBox $raw */
        $raw = $this->raw;
        return $raw;
    }

    /**
     * @param list<array{path: string, line: int, column: int, severity: int, message: string, source: string}> $entries
     */
    public function set(array $entries): void
    {
        $this->clearRows();
        $this->entries = $entries;

        foreach ($entries as $entry) {
            $message = $entry['message'];

            if (strlen($message) > 180) {
                $message = substr($message, 0, 177) . '...';
            }

            $label = RawLabel::new(sprintf(
                '%s:%d:%d  %s — %s',
                $entry['path'],
                $entry['line'] + 1,
                $entry['column'] + 1,
                Diagnostics::severityLabel($entry['severity']),
                $message,
            ));
            $label->setXalign(0.0);
            $label->addCssClass('problem-row');
            $label->addCssClass(match ($entry['severity']) {
                1 => 'problem-error',
                2 => 'problem-warning',
                3 => 'problem-info',
                default => 'problem-hint',
            });

            $row = RawListBoxRow::new();
            $row->setChild($label);
            $row->setActivatable(true);
            $this->list->append($row);
            $this->rows[] = $row;
        }

        $this->summaryLabel->setText($this->summary($entries));
    }

    public function clear(): void
    {
        $this->set([]);
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /** @return list<array{path: string, line: int, column: int, severity: int, message: string, source: string}> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** Simule l'activation d'un problème. */
    public function activateRow(int $index): void
    {
        if (isset($this->rows[$index])) {
            $this->list->emit('row-activated', $this->rows[$index]->handle());
        }
    }

    private function activateIndex(int $index): void
    {
        $entry = $this->entries[$index] ?? null;

        if ($entry === null) {
            return;
        }

        ($this->onOpen)($entry['path'], $entry['line'] + 1);
    }

    /**
     * @param list<array{path: string, line: int, column: int, severity: int, message: string, source: string}> $entries
     */
    private function summary(array $entries): string
    {
        if ($entries === []) {
            return 'Aucun problème';
        }

        $errors = 0;
        $warnings = 0;

        foreach ($entries as $entry) {
            if ($entry['severity'] === 1) {
                $errors++;
            } elseif ($entry['severity'] === 2) {
                $warnings++;
            }
        }

        $parts = [count($entries) . ' problème' . (count($entries) > 1 ? 's' : '')];

        if ($errors > 0) {
            $parts[] = $errors . ' erreur' . ($errors > 1 ? 's' : '');
        }

        if ($warnings > 0) {
            $parts[] = $warnings . ' avertissement' . ($warnings > 1 ? 's' : '');
        }

        return implode(' · ', $parts);
    }

    private function clearRows(): void
    {
        foreach ($this->rows as $row) {
            $this->list->remove($row);
        }

        $this->rows = [];
    }
}
