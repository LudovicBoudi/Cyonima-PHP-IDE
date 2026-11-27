<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Surveillance récursive d'un projet via Gio.
 *
 * Un `GFileMonitor` est créé par dossier (hors dépendances) ; les événements
 * sont regroupés pendant 200 ms avant d'appeler le callback avec la liste des
 * chemins touchés. La création ou la suppression de dossiers reconstruit la
 * liste des moniteurs.
 */
final class WorkspaceWatcher
{
    private const MAX_DIRECTORIES = 200;
    private const DEBOUNCE_MS = 200;

    private const STRUCTURE_EVENTS = [2, 3, 7, 8, 9, 10]; // deleted, created, moved, renamed…

    /** @var list<FileWatcher> */
    private array $watchers = [];

    /** @var array<string, true> */
    private array $pending = [];

    private bool $structureDirty = false;
    private ?int $timer = null;

    /** @var \Closure(list<string>): void */
    private \Closure $onChange;

    private readonly string $root;

    public function __construct(string $root, callable $onChange)
    {
        $this->root = realpath($root) ?: $root;
        $this->onChange = $onChange(...);
        $this->rebuild();
    }

    public function watchCount(): int
    {
        return count($this->watchers);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function cancel(): void
    {
        if ($this->timer !== null) {
            Loop::cancelTimer($this->timer);
            $this->timer = null;
        }

        foreach ($this->watchers as $watcher) {
            $watcher->cancel();
        }

        $this->watchers = [];
        $this->pending = [];
        $this->structureDirty = false;
    }

    public function __destruct()
    {
        $this->cancel();
    }

    private function rebuild(): void
    {
        foreach ($this->watchers as $watcher) {
            $watcher->cancel();
        }

        $this->watchers = [];

        if (!FileWatcher::available()) {
            return;
        }

        foreach ($this->directories() as $directory) {
            $watcher = FileWatcher::directory($directory, function (string $path, int $event): void {
                $this->handle($path, $event);
            });

            if ($watcher !== null) {
                $this->watchers[] = $watcher;
            }
        }
    }

    /** @return list<string> */
    private function directories(): array
    {
        $directories = [$this->root];
        $iterator = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $current): bool {
                if (!$current->isDir()) {
                    return false;
                }

                $parts = explode(DIRECTORY_SEPARATOR, $current->getPathname());

                return array_intersect($parts, ProjectSearch::SKIPPED_DIRECTORIES) === [];
            },
        );

        foreach (new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::SELF_FIRST) as $directory) {
            $directories[] = $directory->getPathname();

            if (count($directories) >= self::MAX_DIRECTORIES) {
                break;
            }
        }

        return $directories;
    }

    private function handle(string $path, int $event): void
    {
        if ($path === '') {
            return;
        }

        $this->pending[$path] = true;

        if (in_array($event, self::STRUCTURE_EVENTS, true)) {
            $this->structureDirty = true;
        }

        $this->schedule();
    }

    private function schedule(): void
    {
        if ($this->timer !== null) {
            Loop::cancelTimer($this->timer);
        }

        $this->timer = Loop::after(self::DEBOUNCE_MS, function (): void {
            $this->timer = null;
            $paths = array_keys($this->pending);
            $this->pending = [];

            if ($this->structureDirty) {
                $this->structureDirty = false;
                $this->rebuild();
            }

            if ($paths !== []) {
                ($this->onChange)($paths);
            }
        });
    }
}
