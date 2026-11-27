<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw\Box as RawBox;
use Cyonima\Gtk\Raw\Label as RawLabel;
use Cyonima\Gtk\Raw\ListBox as RawListBox;
use Cyonima\Gtk\Raw\ListBoxRow as RawListBoxRow;
use Cyonima\Gtk\Raw\Orientation;
use Cyonima\Gtk\Raw\Popover as RawPopover;
use Cyonima\Runtime\GitRepository;

/**
 * Panneau Git : branche, fichiers modifiés, indexation globale et commit.
 */
final class GitPanel extends Widget
{
    private ?GitRepository $repository;
    private string $base;

    private RawLabel $summaryLabel;
    private Button $branchButton;
    private RawPopover $branchPopover;
    private RawListBox $branchList;
    private Entry $branchEntry;
    private Button $branchCreateButton;
    private Entry $mergeEntry;
    private Button $mergeButton;
    private Entry $messageEntry;
    private IconButton $refreshButton;
    private Button $stageButton;
    private Button $commitButton;
    private RawListBox $list;

    /** @var list<RawListBoxRow> */
    private array $rows = [];

    /** @var list<RawListBoxRow> */
    private array $branchRows = [];

    /** @var list<array{path: string, index: string, worktree: string, staged: bool}> */
    private array $entries = [];

    /** @var \Closure(string): void */
    private \Closure $onOpen;

    /** @var (\Closure(string, int): void)|null */
    private ?\Closure $onStatus;

    /** @var (\Closure(string, bool): void)|null */
    private ?\Closure $onMessage;

    /** @var (\Closure(string, string): void)|null */
    private ?\Closure $onDiff;

    /** @var (\Closure(string): void)|null */
    private ?\Closure $onBranchChanged;

    public function __construct(
        string $root,
        callable $onOpen,
        ?callable $onStatus = null,
        ?callable $onMessage = null,
        ?callable $onDiff = null,
        ?callable $onBranchChanged = null,
    ) {
        parent::__construct(RawBox::new(Orientation::VERTICAL, 6));

        $this->repository = GitRepository::discover($root);
        $this->base = $this->repository?->root() ?? (realpath($root) ?: $root);
        $this->onOpen = $onOpen(...);
        $this->onStatus = $onStatus === null ? null : $onStatus(...);
        $this->onMessage = $onMessage === null ? null : $onMessage(...);
        $this->onDiff = $onDiff === null ? null : $onDiff(...);
        $this->onBranchChanged = $onBranchChanged === null ? null : $onBranchChanged(...);

        $this->summaryLabel = RawLabel::new('');
        $this->summaryLabel->setXalign(0.0);
        $this->summaryLabel->addCssClass('git-summary');

        $this->branchButton = (new Button('—'))
            ->cssClass('git-branch')
            ->tooltip('Changer de branche')
            ->onClicked(fn (): bool => $this->openBranches());

        $this->branchList = RawListBox::new();
        $this->branchList->setSelectionMode(1);
        $this->branchList->setActivateOnSingleClick(true);
        $this->branchList->setSizeRequest(220, -1);
        $this->branchList->onRowActivated(function ($listBox, $row): void {
            $wrapped = RawListBoxRow::wrap($row, false);
            $branches = $this->branches();
            $branch = $branches[$wrapped->getIndex()] ?? null;

            if ($branch !== null) {
                $this->switchBranch($branch);
            }
        });

        $branchContent = RawBox::new(Orientation::VERTICAL, 0);
        $branchContent->append($this->branchList);

        $this->branchPopover = RawPopover::new();
        $this->branchPopover->setHasArrow(true);
        $this->branchPopover->setAutohide(true);
        $this->branchPopover->setChild($branchContent);
        $this->branchPopover->setParent($this->branchButton->raw());
        $this->branchPopover->onClosed(function (): void {
            $this->clearBranchRows();
        });

        $this->refreshButton = (new IconButton('view-refresh-symbolic', 'Actualiser Git'))
            ->onClicked(fn (): int => $this->refresh());
        $this->stageButton = (new Button('Indexer tout'))
            ->cssClass('git-action')
            ->onClicked(fn (): bool => $this->stageAll());

        $toolbar = (new Box(Orientation::HORIZONTAL, 4))
            ->cssClass('git-toolbar')
            ->append($this->refreshButton, $this->stageButton);

        $this->branchEntry = new Entry('Nouvelle branche');
        $this->branchCreateButton = (new Button('Créer'))
            ->cssClass('git-action')
            ->onClicked(fn (): array => $this->createBranch($this->branchEntry->text()));
        $this->branchEntry->onActivate(fn (): array => $this->createBranch($this->branchEntry->text()));

        $branchRow = (new Box(Orientation::HORIZONTAL, 4))
            ->append($this->branchEntry->expand(), $this->branchCreateButton);

        $this->mergeEntry = new Entry('Fusionner une branche');
        $this->mergeButton = (new Button('Fusionner'))
            ->cssClass('git-action')
            ->onClicked(fn (): array => $this->mergeBranch($this->mergeEntry->text()));
        $this->mergeEntry->onActivate(fn (): array => $this->mergeBranch($this->mergeEntry->text()));

        $mergeRow = (new Box(Orientation::HORIZONTAL, 4))
            ->append($this->mergeEntry->expand(), $this->mergeButton);

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
        $this->raw()->append($this->branchButton->raw());
        $this->raw()->append($branchRow->raw());
        $this->raw()->append($mergeRow->raw());
        $this->raw()->append($toolbar->raw());
        $this->raw()->append($this->messageEntry->raw());
        $this->raw()->append($this->commitButton->raw());
        $this->raw()->append($this->list);

        $this->refresh();
    }

    public function __destruct()
    {
        if ($this->branchPopover->getVisible()) {
            $this->branchPopover->popdown();
        }

        $this->branchPopover->unparent();
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
        $this->branchButton->label($branch)->sensitive($this->repository !== null);
        $this->branchEntry->sensitive($this->repository !== null);
        $this->branchCreateButton->sensitive($this->repository !== null);
        $this->mergeEntry->sensitive($this->repository !== null);
        $this->mergeButton->sensitive($this->repository !== null);
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

    /** @return list<string> */
    public function branches(): array
    {
        return $this->repository?->branches() ?? [];
    }

    public function openBranches(): bool
    {
        if ($this->repository === null) {
            return false;
        }

        $this->populateBranches();
        $this->branchPopover->popup();

        return true;
    }

    /** @return array{ok: bool, output: string} */
    public function switchBranch(string $branch): array
    {
        if ($this->repository === null) {
            return ['ok' => false, 'output' => 'Dépôt Git introuvable'];
        }

        $result = $this->repository->switchBranch(trim($branch));
        $this->notify(
            $result['ok'] ? 'Git : branche → ' . $branch : 'Git : ' . $result['output'],
            !$result['ok'],
        );

        if ($result['ok']) {
            $this->branchPopover->popdown();
            $this->refresh();

            if ($this->onBranchChanged !== null) {
                ($this->onBranchChanged)($branch);
            }
        }

        return $result;
    }

    /** @return array{ok: bool, output: string} */
    public function createBranch(string $name): array
    {
        if ($this->repository === null) {
            return ['ok' => false, 'output' => 'Dépôt Git introuvable'];
        }

        $name = trim($name);
        $result = $this->repository->createBranch($name);
        $this->notify(
            $result['ok'] ? 'Git : branche créée → ' . $name : 'Git : ' . $result['output'],
            !$result['ok'],
        );

        if ($result['ok']) {
            $this->branchEntry->setText('');
            $this->branchPopover->popdown();
            $this->refresh();

            if ($this->onBranchChanged !== null) {
                ($this->onBranchChanged)($name);
            }
        }

        return $result;
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

    public function focusBranchEntry(): static
    {
        $this->branchEntry->raw()->grabFocus();
        return $this;
    }

    public function setMergeBranch(string $branch): static
    {
        $this->mergeEntry->setText($branch);
        return $this;
    }

    public function focusMergeEntry(): static
    {
        $this->mergeEntry->raw()->grabFocus();
        return $this;
    }

    /** @return array{ok: bool, output: string} */
    public function mergeBranch(string $branch): array
    {
        if ($this->repository === null) {
            return ['ok' => false, 'output' => 'Dépôt Git introuvable'];
        }

        $branch = trim($branch);
        $result = $this->repository->merge($branch);
        $this->notify(
            $result['ok'] ? 'Git : fusion de ' . $branch . ' terminée' : 'Git : ' . $result['output'],
            !$result['ok'],
        );

        // Même en cas de conflit, la liste doit refléter l'état du dépôt.
        $this->refresh();

        if ($result['ok']) {
            $this->mergeEntry->setText('');

            if ($this->onBranchChanged !== null) {
                ($this->onBranchChanged)($this->branch());
            }
        }

        return $result;
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

    public function diffAt(int $index): ?string
    {
        $entry = $this->entries[$index] ?? null;

        return $entry === null ? null : $this->repository?->fileDiff($entry['path']);
    }

    /** Ouvre le diff d'un fichier via le callback fourni. */
    public function showDiff(int $index): bool
    {
        $entry = $this->entries[$index] ?? null;

        if ($entry === null) {
            return false;
        }

        $diff = $this->repository?->fileDiff($entry['path']) ?? '';

        if ($this->onDiff !== null) {
            ($this->onDiff)($entry['path'], $diff);
        }

        return true;
    }

    /** Simule l'activation d'un fichier. */
    public function activateRow(int $index): void
    {
        $this->openIndex($index);
    }

    private function populateBranches(): void
    {
        $this->clearBranchRows();
        $current = $this->branch();

        foreach ($this->branches() as $branch) {
            $label = (new Label(($branch === $current ? '● ' : '') . $branch))
                ->cssClass('git-branch-row');

            $row = RawListBoxRow::new();
            $row->setChild($label->raw());
            $row->setActivatable(true);
            $this->branchList->append($row);
            $this->branchRows[] = $row;

            if ($branch === $current) {
                $this->branchList->selectRow($row);
            }
        }
    }

    private function clearBranchRows(): void
    {
        foreach ($this->branchRows as $row) {
            $this->branchList->remove($row);
        }

        $this->branchRows = [];
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

            $diffButton = (new IconButton('view-reveal-symbolic', 'Diff'))
                ->cssClass('git-row-action')
                ->onClicked(fn (): bool => $this->showDiff($position));

            $fullyStaged = $entry['staged'] && $entry['worktree'] === ' ';
            $toggle = $fullyStaged
                ? (new IconButton('list-remove-symbolic', 'Désindexer'))
                    ->cssClass('git-row-action')
                    ->onClicked(fn (): bool => $this->unstage($entry['path']))
                : (new IconButton('list-add-symbolic', 'Indexer'))
                    ->cssClass('git-row-action')
                    ->onClicked(fn (): bool => $this->stage($entry['path']));

            $content = (new Box(Orientation::HORIZONTAL, 4))
                ->append($label, $open, $diffButton, $toggle);

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
