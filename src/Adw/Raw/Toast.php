<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Adw-1.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Adw\Raw;

class Toast extends \Cyonima\Gtk\Raw\RawObject
{
    public const TYPE_NAME = 'AdwToast';

    public static function new(?string $title): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->adw_toast_new($title);
        return new self(self::adopt($pointer));
    }

    public function setTitle(?string $title): void
    {
        Symbols::get()->adw_toast_set_title($this->handle, $title);
    }

    public function setTimeout(int $timeout): void
    {
        Symbols::get()->adw_toast_set_timeout($this->handle, $timeout);
    }

    public function setButtonLabel(?string $buttonLabel): void
    {
        Symbols::get()->adw_toast_set_button_label($this->handle, $buttonLabel);
    }
}
