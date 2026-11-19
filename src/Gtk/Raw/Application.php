<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Gtk-4.0.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Gtk\Raw;

class Application extends RawObject
{
    public const TYPE_NAME = 'GtkApplication';

    public static function new(?string $applicationId, int $flags): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->gtk_application_new($applicationId, $flags);
        return new self(self::adopt($pointer));
    }
}
