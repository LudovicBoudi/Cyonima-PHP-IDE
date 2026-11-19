<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Gtk-4.0.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Gtk\Raw;

class CssProvider extends RawObject
{
    public const TYPE_NAME = 'GtkCssProvider';

    public static function new(): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->gtk_css_provider_new();
        return new self(self::adopt($pointer));
    }

    public function loadFromString(?string $css): void
    {
        Symbols::get()->gtk_css_provider_load_from_string($this->handle, $css);
    }
}
