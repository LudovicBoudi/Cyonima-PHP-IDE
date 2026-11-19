<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Gtk-4.0.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Gtk\Raw;

class Box extends \Cyonima\Gtk\Raw\Widget
{
    public const TYPE_NAME = 'GtkBox';

    public static function new(int $orientation, int $spacing): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->gtk_box_new($orientation, $spacing);
        return new self(self::adopt($pointer));
    }

    public function append(?\Cyonima\Gtk\Raw\Widget $child): void
    {
        Symbols::get()->gtk_box_append($this->handle, $child?->handle());
    }

    public function remove(?\Cyonima\Gtk\Raw\Widget $child): void
    {
        Symbols::get()->gtk_box_remove($this->handle, $child?->handle());
    }

    public function setSpacing(int $spacing): void
    {
        Symbols::get()->gtk_box_set_spacing($this->handle, $spacing);
    }

    public function getSpacing(): int
    {
        $result = Symbols::get()->gtk_box_get_spacing($this->handle);
        return $result;
    }

    public function setHomogeneous(bool $homogeneous): void
    {
        Symbols::get()->gtk_box_set_homogeneous($this->handle, ($homogeneous) ? 1 : 0);
    }

    public function getHomogeneous(): bool
    {
        $result = Symbols::get()->gtk_box_get_homogeneous($this->handle);
        return ($result) !== 0;
    }
}
