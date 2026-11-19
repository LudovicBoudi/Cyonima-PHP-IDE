<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Gtk-4.0.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Gtk\Raw;

class TextBuffer extends RawObject
{
    public const TYPE_NAME = 'GtkTextBuffer';

    public function setText(?string $text, int $length): void
    {
        Symbols::get()->gtk_text_buffer_set_text($this->handle, $text, $length);
    }

    public function getCharCount(): int
    {
        $result = Symbols::get()->gtk_text_buffer_get_char_count($this->handle);
        return $result;
    }

    public function getLineCount(): int
    {
        $result = Symbols::get()->gtk_text_buffer_get_line_count($this->handle);
        return $result;
    }

    public function setModified(bool $modified): void
    {
        Symbols::get()->gtk_text_buffer_set_modified($this->handle, ($modified) ? 1 : 0);
    }
}
