<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw\Box as RawBox;
use Cyonima\Gtk\Raw\EventControllerKey as RawEventControllerKey;
use Cyonima\Gtk\Raw\Orientation;
use Cyonima\Runtime\GtkText;
use Cyonima\Runtime\QuickOpenMatcher;
use Cyonima\Runtime\SearchHistory;
use Cyonima\Runtime\TextSearch;

/**
 * Barre Rechercher/Remplacer de l'éditeur (Ctrl+F / Ctrl+H).
 */
final class FindBar extends Widget
{
    private const MAX_HIGHLIGHTS = 500;
    private const TAG_MATCH = 'cyonima-find';
    private const TAG_CURRENT = 'cyonima-find-current';

    private Entry $findEntry;
    private Entry $replaceEntry;
    private Label $statusLabel;
    private Button $caseButton;
    private Button $wordButton;
    private Button $regexButton;
    private IconButton $historyButton;

    private SearchHistory $history;
    private int $historyIndex = -1;
    private ?QuickPick $historyPick = null;

    /** @var list<array{start: int, end: int, text: string}> */
    private array $matches = [];

    /** @var array{caseSensitive: bool, wholeWord: bool, regex: bool} */
    private array $options = [
        'caseSensitive' => false,
        'wholeWord' => false,
        'regex' => false,
    ];

    private int $currentIndex = 0;
    private bool $shown = false;
    private bool $suppress = false;

    public function __construct(
        private readonly CodeEditor $editor,
        ?SearchHistory $history = null,
        AdwWindow|Window|null $window = null,
    ) {
        parent::__construct(RawBox::new(Orientation::VERTICAL, 4));

        $this->history = $history ?? SearchHistory::default();
        $this->cssClass('find-bar');
        GtkText::installSearchTags($this->editor->buffer()->handle());

        if ($window !== null) {
            $this->historyPick = new QuickPick(
                $window,
                'Historique de recherches…',
                static fn (array $labels, string $query): array
                    => QuickOpenMatcher::matchLabels($labels, $query),
                function (array $item): void {
                    $this->setQuery($item['label']);
                    $this->findEntry->raw()->grabFocus();
                },
            );
        }

        $this->findEntry = new Entry('Rechercher');
        $this->findEntry->onChanged(fn (): int => $this->refreshMatches());
        $this->findEntry->onActivate(fn (): bool => $this->findNext());

        $this->replaceEntry = new Entry('Remplacer');
        $this->replaceEntry->onActivate(fn (): bool => $this->replaceCurrent());

        $previous = (new IconButton('go-up-symbolic', 'Précédent'))
            ->onClicked(fn (): bool => $this->findPrevious());
        $next = (new IconButton('go-down-symbolic', 'Suivant'))
            ->onClicked(fn (): bool => $this->findNext());
        $close = (new IconButton('window-close-symbolic', 'Fermer'))
            ->onClicked(fn (): static => $this->hide());

        $this->caseButton = $this->optionButton('caseSensitive', 'Aa', 'Respecter la casse');
        $this->wordButton = $this->optionButton('wholeWord', 'Ab|', 'Mot entier');
        $this->regexButton = $this->optionButton('regex', '.*', 'Expression régulière');

        $this->statusLabel = (new Label(''))->cssClass('find-status');

        $this->historyButton = (new IconButton('document-open-recent-symbolic', 'Historique (Alt+↑/↓)'))
            ->onClicked(fn (): static => $this->showHistory());

        $findRow = (new Box(Orientation::HORIZONTAL, 4))
            ->append(
                $this->findEntry->expand(),
                $previous,
                $next,
                $this->caseButton,
                $this->wordButton,
                $this->regexButton,
                $this->statusLabel,
                $this->historyButton,
                $close,
            );

        $replace = (new Button('Remplacer'))
            ->cssClass('find-action')
            ->onClicked(fn (): bool => $this->replaceCurrent());
        $replaceAll = (new Button('Tout remplacer'))
            ->cssClass('find-action')
            ->onClicked(fn (): int => $this->replaceAll());

        $replaceRow = (new Box(Orientation::HORIZONTAL, 4))
            ->append($this->replaceEntry->expand(), $replace, $replaceAll);

        $this->raw()->append($findRow->raw());
        $this->raw()->append($replaceRow->raw());

        $this->findEntry->raw()->addController($this->keyController(
            fn (int $keyval, int $state): bool => $this->handleFindKey($keyval, $state),
        ));
        $this->replaceEntry->raw()->addController($this->keyController(
            fn (int $keyval, int $state): bool => $this->handleReplaceKey($keyval),
        ));

        $this->editor->onChanged(function (): void {
            if ($this->shown && !$this->suppress) {
                $this->refreshMatches();
            }
        });

        $this->visible(false);
    }

    public function raw(): RawBox
    {
        /** @var RawBox $raw */
        $raw = $this->raw;
        return $raw;
    }

    public function show(bool $focusReplace = false): static
    {
        $this->visible(true);
        $this->shown = true;
        $this->refreshMatches();

        if ($focusReplace) {
            $this->replaceEntry->raw()->grabFocus();
        } else {
            $this->findEntry->raw()->grabFocus();
        }

        return $this;
    }

    public function showReplace(): static
    {
        return $this->show(true);
    }

    public function hide(): static
    {
        $this->visible(false);
        $this->shown = false;
        $this->statusLabel->text('');
        $this->clearHighlights();
        $this->editor->grabFocus();

        return $this;
    }

    public function shown(): bool
    {
        return $this->shown;
    }

    public function query(): string
    {
        return $this->findEntry->text();
    }

    public function setQuery(string $query): static
    {
        if ($this->findEntry->text() === $query) {
            $this->refreshMatches();
            return $this;
        }

        $this->findEntry->setText($query);

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

        $this->refreshMatches();

        return $this;
    }

    public function matchCount(): int
    {
        return count($this->matches);
    }

    /** @return list<array{start: int, end: int, text: string}> */
    public function matches(): array
    {
        return $this->matches;
    }

    public function history(): SearchHistory
    {
        return $this->history;
    }

    public function clearHistory(): static
    {
        $this->history->clear();
        $this->historyIndex = -1;
        return $this;
    }

    public function historyPrevious(): static
    {
        if ($this->history->count() === 0) {
            return $this;
        }

        $this->historyIndex = min($this->history->count() - 1, $this->historyIndex + 1);
        $query = $this->history->at($this->historyIndex);

        if ($query !== null) {
            $this->setQuery($query);
        }

        return $this;
    }

    public function historyNext(): static
    {
        if ($this->history->count() === 0 || $this->historyIndex <= 0) {
            return $this;
        }

        $this->historyIndex--;
        $query = $this->history->at($this->historyIndex);

        if ($query !== null) {
            $this->setQuery($query);
        }

        return $this;
    }

    public function showHistory(): static
    {
        if ($this->historyPick === null) {
            return $this->historyPrevious();
        }

        $items = [];

        foreach ($this->history->all() as $query) {
            $items[] = ['label' => $query];
        }

        if ($items === []) {
            return $this;
        }

        $this->historyPick->present($items);

        return $this;
    }

    public function dismissHistory(): static
    {
        $this->historyPick?->dismiss();
        return $this;
    }

    public function currentMatch(): ?array
    {
        return $this->matches[$this->currentIndex] ?? null;
    }

    public function currentIndex(): int
    {
        return $this->currentIndex;
    }

    public function refreshMatches(): int
    {
        try {
            $this->matches = TextSearch::matches(
                $this->editor->text(),
                $this->findEntry->text(),
                $this->options,
            );
        } catch (\InvalidArgumentException) {
            $this->matches = [];
            $this->statusLabel->text('Expression invalide');
            $this->highlightMatches();
            return 0;
        }

        if ($this->matches === []) {
            $this->currentIndex = 0;
            $this->statusLabel->text(
                $this->findEntry->text() === '' ? '' : 'Aucun résultat',
            );
            $this->highlightMatches();
            return 0;
        }

        if ($this->currentIndex >= count($this->matches)) {
            $this->currentIndex = 0;
        }

        $this->updateStatus();
        $this->highlightMatches();

        return count($this->matches);
    }

    public function findNext(): bool
    {
        $this->remember();

        if ($this->refreshMatches() === 0) {
            return false;
        }

        $current = $this->indexAtCursor();
        $index = $current === null
            ? $this->firstIndexAfterCursor()
            : ($current + 1) % count($this->matches);

        $this->selectMatch($index);

        return true;
    }

    public function findPrevious(): bool
    {
        $this->remember();

        if ($this->refreshMatches() === 0) {
            return false;
        }

        $current = $this->indexAtCursor();
        $index = $current === null
            ? $this->lastIndexBeforeCursor()
            : ($current - 1 + count($this->matches)) % count($this->matches);

        $this->selectMatch($index);

        return true;
    }

    public function selectMatch(int $index): static
    {
        if (!isset($this->matches[$index])) {
            return $this;
        }

        $this->currentIndex = $index;
        $match = $this->matches[$index];
        $this->editor->selectRange($match['start'], $match['end']);
        $this->editor->scrollToOffset($match['start']);
        $this->updateStatus();
        $this->highlightMatches();

        return $this;
    }

    public function replaceCurrent(): bool
    {
        $this->remember();

        if ($this->refreshMatches() === 0) {
            return false;
        }

        $index = $this->indexAtCursor();

        if ($index === null) {
            $this->findNext();
            return false;
        }

        $match = $this->matches[$index];
        $replacement = TextSearch::replaceMatch(
            $match['text'],
            $this->findEntry->text(),
            $this->replaceEntry->text(),
            $this->options,
        );

        $this->suppress = true;
        $this->editor->replaceRange($match['start'], $match['end'], $replacement);
        $this->suppress = false;

        $this->refreshMatches();
        $next = $this->firstIndexAtOrAfter($match['start']);

        if ($next !== null) {
            $this->selectMatch($next);
        }

        return true;
    }

    public function replaceAll(): int
    {
        $this->remember();

        if ($this->refreshMatches() === 0) {
            return 0;
        }

        $matches = $this->matches;
        $replacement = $this->replaceEntry->text();
        $query = $this->findEntry->text();

        $this->suppress = true;

        foreach (array_reverse($matches) as $match) {
            $value = TextSearch::replaceMatch($match['text'], $query, $replacement, $this->options);
            $this->editor->replaceRange($match['start'], $match['end'], $value);
        }

        $this->suppress = false;
        $this->refreshMatches();
        $this->statusLabel->text(
            count($matches) . ' remplacement' . (count($matches) > 1 ? 's' : ''),
        );

        return count($matches);
    }

    private function optionButton(string $key, string $label, string $tooltip): Button
    {
        return (new Button($label))
            ->tooltip($tooltip)
            ->cssClass('search-toggle')
            ->onClicked(fn (): static => $this->toggleOption($key));
    }

    private function toggleOption(string $key): static
    {
        $this->setOption($key, !$this->options[$key]);
        return $this;
    }

    private function remember(): void
    {
        $query = trim($this->findEntry->text());

        if ($query === '') {
            return;
        }

        $this->history->add($query);
        $this->historyIndex = -1;
    }

    private function keyController(callable $handler): RawEventControllerKey
    {
        $controller = RawEventControllerKey::new();
        $controller->onKeyPressed(
            fn ($controller, int $keyval, int $code, int $state): bool
                => (bool) $handler($keyval, $state),
        );

        return $controller;
    }

    private function handleFindKey(int $keyval, int $state): bool
    {
        if (($state & 8) !== 0) { // GDK_MOD1_MASK
            if ($keyval === 0xff52) { // Alt+Haut
                $this->historyPrevious();
                return true;
            }

            if ($keyval === 0xff54) { // Alt+Bas
                $this->historyNext();
                return true;
            }
        }

        if ($keyval === 0xff1b) {
            $this->hide();
            return true;
        }

        if ($keyval === 0xff0d || $keyval === 0xff8d) {
            ($state & 1) !== 0 ? $this->findPrevious() : $this->findNext();
            return true;
        }

        return false;
    }

    private function handleReplaceKey(int $keyval): bool
    {
        if ($keyval === 0xff1b) {
            $this->hide();
            return true;
        }

        if ($keyval === 0xff0d || $keyval === 0xff8d) {
            $this->replaceCurrent();
            return true;
        }

        return false;
    }

    private function clearHighlights(): void
    {
        $buffer = $this->editor->buffer()->handle();
        GtkText::removeTagEverywhere($buffer, self::TAG_MATCH);
        GtkText::removeTagEverywhere($buffer, self::TAG_CURRENT);
    }

    private function highlightMatches(): void
    {
        $this->clearHighlights();

        if (!$this->shown || $this->matches === []) {
            return;
        }

        $buffer = $this->editor->buffer()->handle();
        $limit = min(count($this->matches), self::MAX_HIGHLIGHTS);

        for ($index = 0; $index < $limit; $index++) {
            if ($index === $this->currentIndex) {
                continue;
            }

            $match = $this->matches[$index];
            GtkText::applyTagByName($buffer, self::TAG_MATCH, $match['start'], $match['end']);
        }

        $current = $this->currentMatch();

        if ($current !== null) {
            GtkText::applyTagByName($buffer, self::TAG_CURRENT, $current['start'], $current['end']);
        }
    }

    private function updateStatus(): void
    {
        $count = count($this->matches);

        if ($count === 0) {
            $this->statusLabel->text(
                $this->findEntry->text() === '' ? '' : 'Aucun résultat',
            );
            return;
        }

        $this->statusLabel->text(($this->currentIndex + 1) . '/' . $count);
    }

    private function indexAtCursor(): ?int
    {
        $cursor = $this->editor->cursor()['offset'];

        foreach ($this->matches as $index => $match) {
            if ($cursor >= $match['start'] && $cursor <= $match['end']) {
                return $index;
            }
        }

        return null;
    }

    private function firstIndexAfterCursor(): int
    {
        $cursor = $this->editor->cursor()['offset'];

        foreach ($this->matches as $index => $match) {
            if ($match['start'] >= $cursor) {
                return $index;
            }
        }

        return 0;
    }

    private function lastIndexBeforeCursor(): int
    {
        $cursor = $this->editor->cursor()['offset'];

        for ($index = count($this->matches) - 1; $index >= 0; $index--) {
            if ($this->matches[$index]['end'] <= $cursor) {
                return $index;
            }
        }

        return max(0, count($this->matches) - 1);
    }

    private function firstIndexAtOrAfter(int $offset): ?int
    {
        foreach ($this->matches as $index => $match) {
            if ($match['start'] >= $offset) {
                return $index;
            }
        }

        return $this->matches === [] ? null : 0;
    }
}
