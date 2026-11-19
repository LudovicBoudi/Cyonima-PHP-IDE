<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Adw-1.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Adw\Raw;

class WindowTitle extends \Cyonima\Gtk\Raw\Widget
{
    public const TYPE_NAME = 'AdwWindowTitle';

    public static function new(?string $title, ?string $subtitle): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->adw_window_title_new($title, $subtitle);
        return new self(self::adopt($pointer));
    }

    public function setTitle(?string $title): void
    {
        Symbols::get()->adw_window_title_set_title($this->handle, $title);
    }

    public function setSubtitle(?string $subtitle): void
    {
        Symbols::get()->adw_window_title_set_subtitle($this->handle, $subtitle);
    }
}
