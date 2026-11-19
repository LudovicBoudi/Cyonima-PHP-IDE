<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Vte-3.91.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Vte\Raw;

class Terminal extends \Cyonima\Gtk\Raw\Widget
{
    public const TYPE_NAME = 'VteTerminal';

    public const SIGNAL_CHILD_EXITED = 'child-exited';

    public static function new(): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->vte_terminal_new();
        return new self(self::adopt($pointer));
    }

    public function setScrollbackLines(int $lines): void
    {
        Symbols::get()->vte_terminal_set_scrollback_lines($this->handle, $lines);
    }

    public function onChildExited(callable $handler): int
    {
        return $this->connectSignal(self::SIGNAL_CHILD_EXITED, $handler, ['int'], true);
    }
}
