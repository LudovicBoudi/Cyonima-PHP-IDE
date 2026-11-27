<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Runtime\QuickOpenMatcher;

/**
 * Palette de commandes (Ctrl+Shift+P) : mêmes mécanismes que la navigation
 * rapide, appliqués aux actions de l'IDE.
 */
final class CommandPalette
{
    private QuickPick $pick;

    /** @var list<array{label: string, detail: string, action: \Closure}> */
    private array $commands = [];

    /**
     * @param list<array{label: string, detail?: string, action: callable}> $commands
     */
    public function __construct(AdwWindow|Window $window, array $commands)
    {
        foreach ($commands as $command) {
            $label = trim((string) ($command['label'] ?? ''));
            $action = $command['action'] ?? null;

            if ($label === '' || !is_callable($action)) {
                continue;
            }

            $this->commands[] = [
                'label' => $label,
                'detail' => (string) ($command['detail'] ?? ''),
                'action' => $action(...),
            ];
        }

        $this->pick = new QuickPick(
            $window,
            'Exécuter une commande…',
            static fn (array $labels, string $query): array
                => QuickOpenMatcher::matchLabels($labels, $query),
            function (array $item): void {
                foreach ($this->commands as $command) {
                    if ($command['label'] === $item['label']) {
                        ($command['action'])();
                        return;
                    }
                }
            },
        );

        $this->pick->setItems($this->items());
    }

    public function present(): void
    {
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

    public function filter(): int
    {
        return $this->pick->filter();
    }

    public function count(): int
    {
        return $this->pick->count();
    }

    /** @return list<string> libellés filtrés, dans l'ordre affiché */
    public function labels(): array
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

    /** @return list<array{label: string, detail: string, action: \Closure}> */
    public function commands(): array
    {
        return $this->commands;
    }

    /** @return list<array{label: string, detail: string}> */
    private function items(): array
    {
        return array_map(
            static fn (array $command): array => [
                'label' => $command['label'],
                'detail' => $command['detail'],
            ],
            $this->commands,
        );
    }
}
