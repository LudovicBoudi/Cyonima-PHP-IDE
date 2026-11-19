<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Gtk-4.0.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Gtk\Raw;

class Button extends \Cyonima\Gtk\Raw\Widget
{
    public const TYPE_NAME = 'GtkButton';

    public const SIGNAL_CLICKED = 'clicked';

    public static function newWithLabel(?string $label): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->gtk_button_new_with_label($label);
        return new self(self::adopt($pointer));
    }

    public function setLabel(?string $label): void
    {
        Symbols::get()->gtk_button_set_label($this->handle, $label);
    }

    public function getLabel(): ?string
    {
        $result = Symbols::get()->gtk_button_get_label($this->handle);
        return $result;
    }

    public function setHasFrame(bool $hasFrame): void
    {
        Symbols::get()->gtk_button_set_has_frame($this->handle, ($hasFrame) ? 1 : 0);
    }

    public function onClicked(callable $handler): int
    {
        return $this->connectSignal(self::SIGNAL_CLICKED, $handler, 0, true);
    }
}
