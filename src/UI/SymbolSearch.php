<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Runtime\QuickOpenMatcher;
use Cyonima\Runtime\SymbolIndex;

/**
 * Recherche de symboles (Ctrl+T) : classes, interfaces, traits, énumérations,
 * fonctions et méthodes du projet, via le même popover que Ctrl+P.
 */
final class SymbolSearch
{
    private readonly string $root;
    private QuickPick $pick;

    /** @var list<array{name: string, shortName: string, kind: string, path: string, line: int}> */
    private array $symbols = [];

    /** @var array<string, array{name: string, shortName: string, kind: string, path: string, line: int}> */
    private array $byLabel = [];

    /** @var \Closure(string, int): void */
    private \Closure $onOpen;

    public function __construct(AdwWindow|Window $window, string $root, callable $onOpen)
    {
        $this->root = realpath($root) ?: $root;
        $this->onOpen = $onOpen(...);

        $this->pick = new QuickPick(
            $window,
            'Rechercher un symbole…',
            static fn (array $labels, string $query): array
                => QuickOpenMatcher::matchLabels($labels, $query),
            function (array $item): void {
                $symbol = $this->byLabel[$item['label']] ?? null;

                if ($symbol !== null) {
                    ($this->onOpen)($symbol['path'], $symbol['line']);
                }
            },
        );

        $this->refresh();
    }

    public function refresh(): static
    {
        $this->symbols = SymbolIndex::scan($this->root);
        $this->byLabel = [];
        $items = [];
        $seen = [];

        foreach ($this->symbols as $symbol) {
            $label = $symbol['name'] . ' · ' . SymbolIndex::kindLabel($symbol['kind']);

            if (isset($seen[$label])) {
                $label .= ' :' . $symbol['line'];
            }

            $seen[$label] = true;
            $this->byLabel[$label] = $symbol;
            $items[] = [
                'label' => $label,
                'detail' => $symbol['path'] . ':' . $symbol['line'],
            ];
        }

        $this->pick->setItems($items);

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

    public function setQuery(string $query): static
    {
        $this->pick->setQuery($query);
        return $this;
    }

    public function filter(): int
    {
        return $this->pick->filter();
    }

    public function count(): int
    {
        return $this->pick->count();
    }

    /** @return list<string> */
    public function labels(): array
    {
        return $this->pick->labels();
    }

    /** @return list<array{name: string, shortName: string, kind: string, path: string, line: int}> */
    public function symbols(): array
    {
        return $this->symbols;
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

    /** @return list<array{label: string, detail: string}> */
    private function items(): array
    {
        $items = [];

        foreach ($this->byLabel as $label => $symbol) {
            $items[] = [
                'label' => $label,
                'detail' => $symbol['path'] . ':' . $symbol['line'],
            ];
        }

        return $items;
    }
}
