<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Gtk-4.0.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Gtk\Raw;

class GestureClick extends \Cyonima\Gtk\Raw\EventController
{
    public const TYPE_NAME = 'GtkGestureClick';

    public const SIGNAL_PRESSED = 'pressed';

    public static function new(): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->gtk_gesture_click_new();
        return new self(self::adopt($pointer));
    }

    public function onPressed(callable $handler): int
    {
        return $this->connectSignal(self::SIGNAL_PRESSED, $handler, ['int', 'double', 'double'], true);
    }
}
