<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Adw-1.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Adw\Raw;

class StatusPage extends \Cyonima\Gtk\Raw\Widget
{
    public const TYPE_NAME = 'AdwStatusPage';

    public static function new(): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->adw_status_page_new();
        return new self(self::adopt($pointer));
    }

    public function setTitle(?string $title): void
    {
        Symbols::get()->adw_status_page_set_title($this->handle, $title);
    }

    public function setDescription(?string $description): void
    {
        Symbols::get()->adw_status_page_set_description($this->handle, $description);
    }

    public function setIconName(?string $iconName): void
    {
        Symbols::get()->adw_status_page_set_icon_name($this->handle, $iconName);
    }

    public function setChild(?\Cyonima\Gtk\Raw\Widget $child): void
    {
        Symbols::get()->adw_status_page_set_child($this->handle, $child?->handle());
    }
}
