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
 * Panneau de recherche projet : requête, options, résultats, remplacement.
 * Chaque résultat s'ouvre à la ligne exacte dans l'éditeur.
 */
final class SearchPanel extends Widget
{
    private const OPTION_LABELS = [
        'caseSensitive' => 'Respecter la casse',
        'wholeWord' => 'Mot entier',
        'regex' => 'Expression régulière',
    ];

    private readonly string $root;
    private Entry $entry;
    private Button $button;
    private Entry $replaceEntry;
    private Button $replaceButton;
    private Button $caseButton;
    private Button $wordButton;
    private Button $regexButton;
    private RawLabel $countLabel;
    private RawListBox $list;

    /** @var array{caseSensitive: bool, wholeWord: bool, regex: bool} */
    private array $options = [
        'caseSensitive' => false,
        'wholeWord' => false,
        'regex' => false,
    ];

    /** @var list<RawListBoxRow> */
    private array $rows = [];

    /** @var list<array{path: string, line: int}> */
    private array $results = [];

    /** @var \Closure(string, int): void */
    private \Closure $onOpen;

    /** @var (\Closure(array{files: list<array{path: string, count: int}>, changes: int, replacements: int}): void)|null */
    private ?\Closure $onReplaced;

    public function __construct(string $root, callable $onOpen, ?callable $onReplaced = null)
    {
        parent::__construct(RawBox::new(Orientation::VERTICAL, 6));

        $this->root = realpath($root) ?: $root;
        $this->onOpen = $onOpen(...);
        $this->onReplaced = $onReplaced === null ? null : $onReplaced(...);

        $this->entry = (new Entry('Rechercher dans le projet'))
            ->onActivate(fn (): int => $this->runSearch());
        $this->button = (new Button('Rechercher'))
            ->onClicked(fn (): int => $this->runSearch());

        $searchToolbar = (new Box(Orientation::HORIZONTAL, 4))
            ->cssClass('search-toolbar')
            ->append($this->entry->expand(), $this->button);

        $this->caseButton = $this->makeOptionButton('caseSensitive', 'Aa');
        $this->wordButton = $this->makeOptionButton('wholeWord', 'Ab|');
        $this->regexButton = $this->makeOptionButton('regex', '.*');

        $optionsRow = (new Box(Orientation::HORIZONTAL, 4))
            ->cssClass('search-options')
            ->append($this->caseButton, $this->wordButton, $this->regexButton);

        $this->replaceEntry = new Entry('Remplacer par');
        $this->replaceButton = (new Button('Tout remplacer'))
            ->tooltip('Remplacer toutes les occurrences dans le projet')
            ->onClicked(fn (): array => $this->replaceAll());

        $replaceToolbar = (new Box(Orientation::HORIZONTAL, 4))
            ->cssClass('search-toolbar')
            ->append($this->replaceEntry->expand(), $this->replaceButton);

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

        $this->raw()->append($searchToolbar->raw());
        $this->raw()->append($optionsRow->raw());
        $this->raw()->append($this->countLabel);
        $this->raw()->append($this->list);
        $this->raw()->append($replaceToolbar->raw());
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

    public function setQuery(string $query): static
    {
        $this->entry->setText($query);
        return $this;
    }

    public function replacement(): string
    {
        return $this->replaceEntry->text();
    }

    public function setReplacement(string $text): static
    {
        $this->replaceEntry->setText($text);
        return $this;
    }

    public function resultCount(): int
    {
        return count($this->results);
    }

    /** @return array{caseSensitive: bool, wholeWord: bool, regex: bool} */
    public function options(): array
    {
        return $this->options;
    }

    public function setOption(string $key, bool $value): static
    {
        if (!array_key_exists($key, $this->options)) {
            return $this;
        }

        $button = match ($key) {
            'caseSensitive' => $this->caseButton,
            'wholeWord' => $this->wordButton,
            'regex' => $this->regexButton,
        };

        $this->options[$key] = $value;

        if ($value) {
            $button->cssClass('active');
        } else {
            $button->removeCssClass('active');
        }

        return $this;
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
        $this->clearResults();

        try {
            $matches = ProjectSearch::search($this->root, $query, 200, $this->options);
        } catch (\InvalidArgumentException) {
            $this->countLabel->setText('Expression invalide');
            return 0;
        }

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

    /**
     * Remplace toutes les occurrences puis rafraîchit les résultats.
     *
     * @return array{files: list<array{path: string, count: int}>, changes: int, replacements: int}
     */
    public function replaceAll(): array
    {
        $query = $this->entry->text();

        try {
            $report = ProjectSearch::replace(
                $this->root,
                $query,
                $this->replaceEntry->text(),
                $this->options,
            );
        } catch (\InvalidArgumentException) {
            $this->countLabel->setText('Expression invalide');
            return ['files' => [], 'changes' => 0, 'replacements' => 0];
        }

        $remaining = $this->search($query);

        if ($report['replacements'] > 0) {
            $this->countLabel->setText(sprintf(
                '%d remplacement(s) dans %d fichier(s) · %d résultat(s)',
                $report['replacements'],
                $report['changes'],
                $remaining,
            ));
        }

        if ($this->onReplaced !== null) {
            ($this->onReplaced)($report);
        }

        return $report;
    }

    /** Simule l'activation d'un résultat. */
    public function activateRow(int $index): void
    {
        if (isset($this->rows[$index])) {
            $this->list->emit('row-activated', $this->rows[$index]->handle());
        }
    }

    private function makeOptionButton(string $key, string $label): Button
    {
        return (new Button($label))
            ->tooltip(self::OPTION_LABELS[$key])
            ->cssClass('search-toggle')
            ->onClicked(fn (): static => $this->toggleOption($key));
    }

    private function toggleOption(string $key): static
    {
        $this->setOption($key, !$this->options[$key]);

        if (trim($this->entry->text()) !== '') {
            $this->refresh();
        }

        return $this;
    }

    private function clearResults(): void
    {
        foreach ($this->rows as $row) {
            $this->list->remove($row);
        }

        $this->rows = [];
        $this->results = [];
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
