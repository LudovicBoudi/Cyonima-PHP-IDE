<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Gtk-4.0.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Gtk\Raw;

class Image extends \Cyonima\Gtk\Raw\Widget
{
    public const TYPE_NAME = 'GtkImage';

    public static function new(): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->gtk_image_new();
        return new self(self::adopt($pointer));
    }

    public static function newFromFile(?string $file): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->gtk_image_new_from_file($file);
        return new self(self::adopt($pointer));
    }

    public function setFromFile(?string $file): void
    {
        Symbols::get()->gtk_image_set_from_file($this->handle, $file);
    }

    public function setPixelSize(int $pixelSize): void
    {
        Symbols::get()->gtk_image_set_pixel_size($this->handle, $pixelSize);
    }
}
