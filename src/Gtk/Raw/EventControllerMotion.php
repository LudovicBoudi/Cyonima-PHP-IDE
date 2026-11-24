<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Gtk-4.0.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Gtk\Raw;

class EventControllerMotion extends \Cyonima\Gtk\Raw\EventController
{
    public const TYPE_NAME = 'GtkEventControllerMotion';

    public const SIGNAL_ENTER = 'enter';
    public const SIGNAL_LEAVE = 'leave';
    public const SIGNAL_MOTION = 'motion';

    public static function new(): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->gtk_event_controller_motion_new();
        return new self(self::adopt($pointer));
    }

    public function onEnter(callable $handler): int
    {
        return $this->connectSignal(self::SIGNAL_ENTER, $handler, ['double', 'double'], true);
    }

    public function onLeave(callable $handler): int
    {
        return $this->connectSignal(self::SIGNAL_LEAVE, $handler, [], true);
    }

    public function onMotion(callable $handler): int
    {
        return $this->connectSignal(self::SIGNAL_MOTION, $handler, ['double', 'double'], true);
    }
}
