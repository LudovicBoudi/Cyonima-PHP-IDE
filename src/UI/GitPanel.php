<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw\Box as RawBox;
use Cyonima\Gtk\Raw\Label as RawLabel;
use Cyonima\Gtk\Raw\ListBox as RawListBox;
use Cyonima\Gtk\Raw\ListBoxRow as RawListBoxRow;
use Cyonima\Gtk\Raw\Orientation;
use Cyonima\Runtime\GitRepository;

/**
 * Panneau Git : branche, fichiers modifiés, indexation globale et commit.
 */
final class GitPanel extends Widget
{
    private ?GitRepository $repository;
    private string $base;

    private RawLabel $summaryLabel;
    private Entry $messageEntry;
    private IconButton $refreshButton;
    private Button $stageButton;
    private Button $commitButton;
    private RawListBox $list;

    /** @var list<RawListBoxRow> */
    private array $rows = [];

    /** @var list<array{path: string, index: string, worktree: string, staged: bool}> */
    private array $entries = [];

    /** @var \Closure(string): void */
    private \Closure $onOpen;

    /** @var (\Closure(string, int): void)|null */
    private ?\Closure $onStatus;

    /** @var (\Closure(string, bool): void)|null */
    private ?\Closure $onMessage;

    public function __construct(
        string $root,
        callable $onOpen,
        ?callable $onStatus = null,
        ?callable $onMessage = null,
    ) {
        parent::__construct(RawBox::new(Orientation::VERTICAL, 6));

        $this->repository = GitRepository::discover($root);
        $this->base = $this->repository?->root() ?? (realpath($root) ?: $root);
        $this->onOpen = $onOpen(...);
        $this->onStatus = $onStatus === null ? null : $onStatus(...);
        $this->onMessage = $onMessage === null ? null : $onMessage(...);

        $this->summaryLabel = RawLabel::new('');
        $this->summaryLabel->setXalign(0.0);
        $this->summaryLabel->addCssClass('git-summary');

        $this->refreshButton = (new IconButton('view-refresh-symbolic', 'Actualiser Git'))
            ->onClicked(fn (): int => $this->refresh());
        $this->stageButton = (new Button('Indexer tout'))
            ->cssClass('git-action')
            ->onClicked(fn (): bool => $this->stageAll());

        $toolbar = (new Box(Orientation::HORIZONTAL, 4))
            ->cssClass('git-toolbar')
            ->append($this->refreshButton, $this->stageButton);

        $this->messageEntry = new Entry('Message de commit');
        $this->commitButton = (new Button('Commit'))
            ->cssClass('git-action')
            ->onClicked(fn (): array => $this->commit());
        $this->messageEntry->onActivate(fn (): array => $this->commit());

        $this->list = RawListBox::new();
        $this->list->setSelectionMode(1);
        $this->list->setActivateOnSingleClick(true);
        $this->list->onRowActivated(function ($listBox, $row): void {
            $wrapped = RawListBoxRow::wrap($row, false);
            $this->openIndex($wrapped->getIndex());
        });

        $this->raw()->append($this->summaryLabel);
        $this->raw()->append($toolbar->raw());
        $this->raw()->append($this->messageEntry->raw());
        $this->raw()->append($this->commitButton->raw());
        $this->raw()->append($this->list);

        $this->refresh();
    }

    public function raw(): RawBox
    {
        /** @var RawBox $raw */
        $raw = $this->raw;
        return $raw;
    }

    public function refresh(): int
    {
        $this->entries = $this->repository?->status() ?? [];
        $branch = $this->repository?->branch() ?? '—';
        $staged = 0;

        foreach ($this->entries as $entry) {
            if ($entry['staged']) {
                $staged++;
            }
        }

        if ($this->repository === null) {
            $this->summaryLabel->setText('Dépôt Git introuvable');
        } else {
            $this->summaryLabel->setText(sprintf(
                '%s · %d modification%s · %d indexée%s',
                $branch,
                count($this->entries),
                count($this->entries) > 1 ? 's' : '',
                $staged,
                $staged > 1 ? 's' : '',
            ));
        }

        $this->stageButton->sensitive($this->repository !== null);
        $this->commitButton->sensitive($this->repository !== null);
        $this->buildRows();

        if ($this->onStatus !== null) {
            ($this->onStatus)($branch, count($this->entries));
        }

        return count($this->entries);
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /** @return list<array{path: string, index: string, worktree: string, staged: bool}> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function branch(): string
    {
        return $this->repository?->branch() ?? '—';
    }

    public function repository(): ?GitRepository
    {
        return $this->repository;
    }

    public function message(): string
    {
        return $this->messageEntry->text();
    }

    public function setMessage(string $message): static
    {
        $this->messageEntry->setText($message);
        return $this;
    }

    public function stageAll(): bool
    {
        if ($this->repository === null) {
            return false;
        }

        $ok = $this->repository->stageAll();
        $this->notify(
            $ok ? 'Git : fichiers indexés' : 'Git : indexation impossible',
            !$ok,
        );
        $this->refresh();

        return $ok;
    }

    public function stage(string $path): bool
    {
        if ($this->repository === null) {
            return false;
        }

        $ok = $this->repository->stage([$path]);
        $this->notify(
            $ok ? 'Git : indexé → ' . $path : 'Git : indexation impossible → ' . $path,
            !$ok,
        );
        $this->refresh();

        return $ok;
    }

    public function unstage(string $path): bool
    {
        if ($this->repository === null) {
            return false;
        }

        $ok = $this->repository->unstage([$path]);
        $this->notify(
            $ok ? 'Git : désindexé → ' . $path : 'Git : désindexation impossible → ' . $path,
            !$ok,
        );
        $this->refresh();

        return $ok;
    }

    /**
     * Indexe le fichier, ou le désindexe s'il est déjà entièrement indexé.
     */
    public function stageRow(int $index): bool
    {
        $entry = $this->entries[$index] ?? null;

        if ($entry === null) {
            return false;
        }

        if ($entry['staged'] && $entry['worktree'] === ' ') {
            return $this->unstage($entry['path']);
        }

        return $this->stage($entry['path']);
    }

    /** @return array{ok: bool, output: string} */
    public function commit(): array
    {
        if ($this->repository === null) {
            return ['ok' => false, 'output' => 'Dépôt Git introuvable'];
        }

        $result = $this->repository->commit($this->message());

        $this->notify(
            $result['ok'] ? 'Git : commit créé' : 'Git : ' . $result['output'],
            !$result['ok'],
        );

        if ($result['ok']) {
            $this->messageEntry->setText('');
            $this->refresh();
        }

        return $result;
    }

    /** Diff non indexé suivi du diff indexé. */
    public function diff(): string
    {
        if ($this->repository === null) {
            return '';
        }

        $parts = array_filter([
            $this->repository->diff(),
            $this->repository->stagedDiff(),
        ], static fn (string $diff): bool => trim($diff) !== '');

        return implode("\n", $parts);
    }

    /** Simule l'activation d'un fichier. */
    public function activateRow(int $index): void
    {
        $this->openIndex($index);
    }

    private function buildRows(): void
    {
        foreach ($this->rows as $row) {
            $this->list->remove($row);
        }

        $this->rows = [];

        foreach ($this->entries as $position => $entry) {
            $label = (new Label(sprintf(
                '%s%s  %s',
                $entry['index'],
                $entry['worktree'],
                $entry['path'],
            )))
                ->cssClass('git-row')
                ->expand(true, false);

            $label->cssClass(match (true) {
                $entry['index'] === '?' => 'git-untracked',
                $entry['staged'] => 'git-staged',
                default => 'git-modified',
            });

            $open = (new IconButton('document-open-symbolic', 'Ouvrir'))
                ->cssClass('git-row-action')
                ->onClicked(fn (): int => $this->openIndex($position));

            $fullyStaged = $entry['staged'] && $entry['worktree'] === ' ';
            $toggle = $fullyStaged
                ? (new IconButton('list-remove-symbolic', 'Désindexer'))
                    ->cssClass('git-row-action')
                    ->onClicked(fn (): bool => $this->unstage($entry['path']))
                : (new IconButton('list-add-symbolic', 'Indexer'))
                    ->cssClass('git-row-action')
                    ->onClicked(fn (): bool => $this->stage($entry['path']));

            $content = (new Box(Orientation::HORIZONTAL, 4))
                ->append($label, $open, $toggle);

            $row = RawListBoxRow::new();
            $row->setChild($content->raw());
            $row->setActivatable(false);
            $this->list->append($row);
            $this->rows[] = $row;
        }
    }

    private function openIndex(int $index): int
    {
        $entry = $this->entries[$index] ?? null;

        if ($entry === null) {
            return 0;
        }

        ($this->onOpen)($this->base . '/' . $entry['path']);

        return 1;
    }

    private function notify(string $message, bool $error): void
    {
        if ($this->onMessage !== null) {
            ($this->onMessage)($message, $error);
        }
    }
}
