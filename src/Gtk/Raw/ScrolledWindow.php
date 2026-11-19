<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Gtk-4.0.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Gtk\Raw;

class ScrolledWindow extends \Cyonima\Gtk\Raw\Widget
{
    public const TYPE_NAME = 'GtkScrolledWindow';

    public static function new(): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->gtk_scrolled_window_new();
        return new self(self::adopt($pointer));
    }

    public function setChild(?\Cyonima\Gtk\Raw\Widget $child): void
    {
        Symbols::get()->gtk_scrolled_window_set_child($this->handle, $child?->handle());
    }
}
