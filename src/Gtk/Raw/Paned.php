<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Gtk-4.0.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Gtk\Raw;

class Paned extends \Cyonima\Gtk\Raw\Widget
{
    public const TYPE_NAME = 'GtkPaned';

    public static function new(int $orientation): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->gtk_paned_new($orientation);
        return new self(self::adopt($pointer));
    }

    public function setStartChild(?\Cyonima\Gtk\Raw\Widget $child): void
    {
        Symbols::get()->gtk_paned_set_start_child($this->handle, $child?->handle());
    }

    public function setEndChild(?\Cyonima\Gtk\Raw\Widget $child): void
    {
        Symbols::get()->gtk_paned_set_end_child($this->handle, $child?->handle());
    }

    public function setPosition(int $position): void
    {
        Symbols::get()->gtk_paned_set_position($this->handle, $position);
    }

    public function setResizeStartChild(bool $resize): void
    {
        Symbols::get()->gtk_paned_set_resize_start_child($this->handle, ($resize) ? 1 : 0);
    }

    public function setShrinkStartChild(bool $shrink): void
    {
        Symbols::get()->gtk_paned_set_shrink_start_child($this->handle, ($shrink) ? 1 : 0);
    }
}
