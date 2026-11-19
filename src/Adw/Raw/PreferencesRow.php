<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Adw-1.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Adw\Raw;

class PreferencesRow extends \Cyonima\Gtk\Raw\ListBoxRow
{
    public const TYPE_NAME = 'AdwPreferencesRow';

    public static function new(): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->adw_preferences_row_new();
        return new self(self::adopt($pointer));
    }

    public function setTitle(?string $title): void
    {
        Symbols::get()->adw_preferences_row_set_title($this->handle, $title);
    }

    public function setTitleSelectable(bool $titleSelectable): void
    {
        Symbols::get()->adw_preferences_row_set_title_selectable($this->handle, ($titleSelectable) ? 1 : 0);
    }
}
