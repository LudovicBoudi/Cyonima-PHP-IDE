<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Gtk-4.0.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Gtk\Raw;

class Fixed extends \Cyonima\Gtk\Raw\Widget
{
    public const TYPE_NAME = 'GtkFixed';

    public static function new(): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->gtk_fixed_new();
        return new self(self::adopt($pointer));
    }

    public function put(?\Cyonima\Gtk\Raw\Widget $widget, float $x, float $y): void
    {
        Symbols::get()->gtk_fixed_put($this->handle, $widget?->handle(), $x, $y);
    }

    public function move(?\Cyonima\Gtk\Raw\Widget $widget, float $x, float $y): void
    {
        Symbols::get()->gtk_fixed_move($this->handle, $widget?->handle(), $x, $y);
    }

    public function remove(?\Cyonima\Gtk\Raw\Widget $widget): void
    {
        Symbols::get()->gtk_fixed_remove($this->handle, $widget?->handle());
    }
}
