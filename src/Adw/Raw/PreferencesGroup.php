<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Adw-1.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Adw\Raw;

class PreferencesGroup extends \Cyonima\Gtk\Raw\Widget
{
    public const TYPE_NAME = 'AdwPreferencesGroup';

    public static function new(): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->adw_preferences_group_new();
        return new self(self::adopt($pointer));
    }

    public function add(?\Cyonima\Gtk\Raw\Widget $row): void
    {
        Symbols::get()->adw_preferences_group_add($this->handle, $row?->handle());
    }

    public function setTitle(?string $title): void
    {
        Symbols::get()->adw_preferences_group_set_title($this->handle, $title);
    }

    public function setDescription(?string $description): void
    {
        Symbols::get()->adw_preferences_group_set_description($this->handle, $description);
    }
}
