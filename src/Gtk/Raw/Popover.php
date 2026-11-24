<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Gtk-4.0.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Gtk\Raw;

class Popover extends \Cyonima\Gtk\Raw\Widget
{
    public const TYPE_NAME = 'GtkPopover';

    public const SIGNAL_CLOSED = 'closed';

    public static function new(): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->gtk_popover_new();
        return new self(self::adopt($pointer));
    }

    public function setChild(?\Cyonima\Gtk\Raw\Widget $child): void
    {
        Symbols::get()->gtk_popover_set_child($this->handle, $child?->handle());
    }

    public function setHasArrow(bool $hasArrow): void
    {
        Symbols::get()->gtk_popover_set_has_arrow($this->handle, ($hasArrow) ? 1 : 0);
    }

    public function setAutohide(bool $autohide): void
    {
        Symbols::get()->gtk_popover_set_autohide($this->handle, ($autohide) ? 1 : 0);
    }

    public function setPointingTo(?\FFI\CData $pointingTo): void
    {
        Symbols::get()->gtk_popover_set_pointing_to($this->handle, $pointingTo);
    }

    public function popup(): void
    {
        Symbols::get()->gtk_popover_popup($this->handle);
    }

    public function popdown(): void
    {
        Symbols::get()->gtk_popover_popdown($this->handle);
    }

    public function onClosed(callable $handler): int
    {
        return $this->connectSignal(self::SIGNAL_CLOSED, $handler, [], true);
    }
}
