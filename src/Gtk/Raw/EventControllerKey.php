<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Gtk-4.0.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Gtk\Raw;

class EventControllerKey extends \Cyonima\Gtk\Raw\EventController
{
    public const TYPE_NAME = 'GtkEventControllerKey';

    public const SIGNAL_KEY_PRESSED = 'key-pressed';

    public static function new(): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->gtk_event_controller_key_new();
        return new self(self::adopt($pointer));
    }

    public function onKeyPressed(callable $handler): int
    {
        return $this->connectSignal(self::SIGNAL_KEY_PRESSED, $handler, ['int', 'int', 'int'], false);
    }
}
