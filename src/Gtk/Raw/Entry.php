<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Gtk-4.0.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Gtk\Raw;

class Entry extends \Cyonima\Gtk\Raw\Widget
{
    public const TYPE_NAME = 'GtkEntry';

    public const SIGNAL_ACTIVATE = 'activate';

    public static function new(): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->gtk_entry_new();
        return new self(self::adopt($pointer));
    }

    public function setPlaceholderText(?string $placeholderText): void
    {
        Symbols::get()->gtk_entry_set_placeholder_text($this->handle, $placeholderText);
    }

    public function onActivate(callable $handler): int
    {
        return $this->connectSignal(self::SIGNAL_ACTIVATE, $handler, [], true);
    }
}
