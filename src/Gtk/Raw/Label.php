<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Gtk-4.0.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Gtk\Raw;

class Label extends \Cyonima\Gtk\Raw\Widget
{
    public const TYPE_NAME = 'GtkLabel';

    public static function new(?string $text): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->gtk_label_new($text);
        return new self(self::adopt($pointer));
    }

    public function setText(?string $text): void
    {
        Symbols::get()->gtk_label_set_text($this->handle, $text);
    }

    public function getText(): ?string
    {
        $result = Symbols::get()->gtk_label_get_text($this->handle);
        return $result;
    }

    public function setXalign(float $xalign): void
    {
        Symbols::get()->gtk_label_set_xalign($this->handle, $xalign);
    }

    public function setYalign(float $yalign): void
    {
        Symbols::get()->gtk_label_set_yalign($this->handle, $yalign);
    }

    public function setWrap(bool $wrap): void
    {
        Symbols::get()->gtk_label_set_wrap($this->handle, ($wrap) ? 1 : 0);
    }

    public function setSelectable(bool $selectable): void
    {
        Symbols::get()->gtk_label_set_selectable($this->handle, ($selectable) ? 1 : 0);
    }

    public function setMarkup(?string $markup): void
    {
        Symbols::get()->gtk_label_set_markup($this->handle, $markup);
    }
}
