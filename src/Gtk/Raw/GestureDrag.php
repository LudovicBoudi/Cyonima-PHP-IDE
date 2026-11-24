<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Gtk-4.0.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Gtk\Raw;

class GestureDrag extends \Cyonima\Gtk\Raw\EventController
{
    public const TYPE_NAME = 'GtkGestureDrag';

    public const SIGNAL_DRAG_BEGIN = 'drag-begin';
    public const SIGNAL_DRAG_END = 'drag-end';
    public const SIGNAL_DRAG_UPDATE = 'drag-update';

    public static function new(): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->gtk_gesture_drag_new();
        return new self(self::adopt($pointer));
    }

    public function onDragBegin(callable $handler): int
    {
        return $this->connectSignal(self::SIGNAL_DRAG_BEGIN, $handler, ['double', 'double'], true);
    }

    public function onDragEnd(callable $handler): int
    {
        return $this->connectSignal(self::SIGNAL_DRAG_END, $handler, ['double', 'double'], true);
    }

    public function onDragUpdate(callable $handler): int
    {
        return $this->connectSignal(self::SIGNAL_DRAG_UPDATE, $handler, ['double', 'double'], true);
    }
}
