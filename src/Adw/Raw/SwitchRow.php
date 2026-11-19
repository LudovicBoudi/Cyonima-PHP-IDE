<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Adw-1.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Adw\Raw;

class SwitchRow extends \Cyonima\Adw\Raw\ActionRow
{
    public const TYPE_NAME = 'AdwSwitchRow';

    public static function new(): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->adw_switch_row_new();
        return new self(self::adopt($pointer));
    }

    public function setActive(bool $active): void
    {
        Symbols::get()->adw_switch_row_set_active($this->handle, ($active) ? 1 : 0);
    }

    public function getActive(): bool
    {
        $result = Symbols::get()->adw_switch_row_get_active($this->handle);
        return ($result) !== 0;
    }
}
