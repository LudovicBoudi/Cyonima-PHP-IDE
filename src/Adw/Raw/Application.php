<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Adw-1.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Adw\Raw;

class Application extends \Cyonima\Gtk\Raw\Application
{
    public const TYPE_NAME = 'AdwApplication';

    public static function new(?string $applicationId, int $flags): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->adw_application_new($applicationId, $flags);
        return new self(self::adopt($pointer));
    }
}
