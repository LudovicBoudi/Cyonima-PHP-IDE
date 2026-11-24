<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Gtk-4.0.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Gtk\Raw;

class Video extends \Cyonima\Gtk\Raw\Widget
{
    public const TYPE_NAME = 'GtkVideo';

    public static function new(): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->gtk_video_new();
        return new self(self::adopt($pointer));
    }

    public static function newForFilename(?string $filename): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->gtk_video_new_for_filename($filename);
        return new self(self::adopt($pointer));
    }

    public function setFilename(?string $filename): void
    {
        Symbols::get()->gtk_video_set_filename($this->handle, $filename);
    }

    public function setAutoplay(bool $autoplay): void
    {
        Symbols::get()->gtk_video_set_autoplay($this->handle, ($autoplay) ? 1 : 0);
    }

    public function setLoop(bool $loop): void
    {
        Symbols::get()->gtk_video_set_loop($this->handle, ($loop) ? 1 : 0);
    }
}
