<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Adw-1.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Adw\Raw;

class ToastOverlay extends \Cyonima\Gtk\Raw\Widget
{
    public const TYPE_NAME = 'AdwToastOverlay';

    public static function new(): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->adw_toast_overlay_new();
        return new self(self::adopt($pointer));
    }

    public function setChild(?\Cyonima\Gtk\Raw\Widget $child): void
    {
        Symbols::get()->adw_toast_overlay_set_child($this->handle, $child?->handle());
    }

    public function addToast(?\Cyonima\Adw\Raw\Toast $toast): void
    {
        Symbols::get()->adw_toast_overlay_add_toast($this->handle, $toast?->handle());
    }
}
