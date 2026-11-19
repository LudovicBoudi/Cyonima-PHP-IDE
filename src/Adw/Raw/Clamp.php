<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Adw-1.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Adw\Raw;

class Clamp extends \Cyonima\Gtk\Raw\Widget
{
    public const TYPE_NAME = 'AdwClamp';

    public static function new(): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->adw_clamp_new();
        return new self(self::adopt($pointer));
    }

    public function setChild(?\Cyonima\Gtk\Raw\Widget $child): void
    {
        Symbols::get()->adw_clamp_set_child($this->handle, $child?->handle());
    }

    public function setMaximumSize(int $maximumSize): void
    {
        Symbols::get()->adw_clamp_set_maximum_size($this->handle, $maximumSize);
    }

    public function setTighteningThreshold(int $tighteningThreshold): void
    {
        Symbols::get()->adw_clamp_set_tightening_threshold($this->handle, $tighteningThreshold);
    }
}
