<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Runtime\Vte;
use Cyonima\Vte\Raw\Terminal as RawTerminal;

/** Terminal natif (VTE) avec PTY réel. */
final class Terminal extends Widget
{
    public function __construct(int $scrollbackLines = 10000)
    {
        parent::__construct(RawTerminal::new());
        $this->raw()->setScrollbackLines($scrollbackLines);
    }

    public function raw(): RawTerminal
    {
        /** @var RawTerminal $raw */
        $raw = $this->raw;
        return $raw;
    }

    /**
     * Lance un programme dans le PTY.
     *
     * @param list<string> $command
     * @param null|callable(int $pid, ?string $error): void $onSpawn
     */
    public function spawn(array $command, ?string $cwd = null, ?callable $onSpawn = null): static
    {
        Vte::spawn($this->raw()->handle(), $command, $cwd, $onSpawn);
        return $this;
    }

    /** Envoie des octets au programme attaché (commandes, frappe…). */
    public function write(string $data): static
    {
        Vte::feed($this->raw()->handle(), $data);
        return $this;
    }

    /** Le handler reçoit le terminal façade et le statut d'attente du fils. */
    public function onChildExited(callable $handler): static
    {
        // Notification courte : pas besoin de fibre, et cela évite tout
        // changement de contexte si le signal part pendant la destruction.
        $this->raw()->connectSignal(
            RawTerminal::SIGNAL_CHILD_EXITED,
            fn ($raw, $status) => $handler($this, $status),
            ['int'],
            false,
        );
        return $this;
    }
}
