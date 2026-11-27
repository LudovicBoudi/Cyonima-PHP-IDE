<?php

declare(strict_types=1);

namespace Cyonima\UI;

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
    private QuickPick $pick;

    /** @var list<string> chemins relatifs du projet */
    private array $paths = [];

    /** @var \Closure(string): void */
    private \Closure $onOpen;

    public function __construct(AdwWindow|Window $window, string $root, callable $onOpen)
    {
        $this->root = realpath($root) ?: $root;
        $this->onOpen = $onOpen(...);
        $this->paths = $this->candidates();

        $this->pick = new QuickPick(
            $window,
            'Aller au fichier…',
            static fn (array $labels, string $query): array
                => QuickOpenMatcher::match($labels, $query, self::MAX_RESULTS),
            function (array $item): void {
                ($this->onOpen)($this->root . DIRECTORY_SEPARATOR . $item['label']);
            },
        );

        $this->pick->setItems($this->items());
    }

    /** Recharge les fichiers du projet avant chaque ouverture. */
    public function refresh(): static
    {
        $this->paths = $this->candidates();
        $this->pick->setItems($this->items());

        return $this;
    }

    public function present(): void
    {
        $this->refresh();
        $this->pick->present($this->items());
    }

    public function dismiss(): void
    {
        $this->pick->dismiss();
    }

    public function visible(): bool
    {
        return $this->pick->visible();
    }

    public function query(): string
    {
        return $this->pick->query();
    }

    public function setQuery(string $query): static
    {
        $this->pick->setQuery($query);
        return $this;
    }

    public function count(): int
    {
        return $this->pick->count();
    }

    /** @return list<string> */
    public function matches(): array
    {
        return $this->pick->labels();
    }

    public function move(int $delta): void
    {
        $this->pick->move($delta);
    }

    public function chooseCurrent(): int
    {
        return $this->pick->chooseCurrent();
    }

    public function choose(int $index): int
    {
        return $this->pick->choose($index);
    }

    public function filter(): int
    {
        return $this->pick->filter();
    }

    /** @return list<string> */
    private function candidates(): array
    {
        $paths = [];

        foreach (ProjectSearch::files($this->root) as $path) {
            $paths[] = str_starts_with($path, $this->root . DIRECTORY_SEPARATOR)
                ? substr($path, strlen($this->root) + 1)
                : $path;
        }

        return $paths;
    }

    /** @return list<array{label: string}> */
    private function items(): array
    {
        return array_map(
            static fn (string $path): array => ['label' => $path],
            $this->paths,
        );
    }
}
