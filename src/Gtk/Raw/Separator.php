<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Gtk-4.0.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Gtk\Raw;

class Separator extends \Cyonima\Gtk\Raw\Widget
{
    public const TYPE_NAME = 'GtkSeparator';

    public static function new(int $orientation): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->gtk_separator_new($orientation);
        return new self(self::adopt($pointer));
    }
}
