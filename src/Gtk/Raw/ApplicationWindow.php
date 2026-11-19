<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Gtk-4.0.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Gtk\Raw;

class ApplicationWindow extends \Cyonima\Gtk\Raw\Window
{
    public const TYPE_NAME = 'GtkApplicationWindow';

    public static function create(?\Cyonima\Gtk\Raw\Application $application): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->gtk_application_window_new($application?->handle());
        return new self(self::adopt($pointer));
    }
}
