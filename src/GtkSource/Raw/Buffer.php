<?php

declare(strict_types=1);

/**
 * Fichier généré depuis GtkSource-5.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\GtkSource\Raw;

class Buffer extends \Cyonima\Gtk\Raw\TextBuffer
{
    public const TYPE_NAME = 'GtkSourceBuffer';

    public static function new(?\FFI\CData $tagTable): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->gtk_source_buffer_new($tagTable);
        return new self(self::adopt($pointer));
    }

    public function setLanguage(?\FFI\CData $language): void
    {
        Symbols::get()->gtk_source_buffer_set_language($this->handle, $language);
    }

    public function getLanguage(): ?\FFI\CData
    {
        $result = Symbols::get()->gtk_source_buffer_get_language($this->handle);
        return $result;
    }

    public function setHighlightSyntax(bool $highlightSyntax): void
    {
        Symbols::get()->gtk_source_buffer_set_highlight_syntax($this->handle, ($highlightSyntax) ? 1 : 0);
    }

    public function getHighlightSyntax(): bool
    {
        $result = Symbols::get()->gtk_source_buffer_get_highlight_syntax($this->handle);
        return ($result) !== 0;
    }

    public function setStyleScheme(?\FFI\CData $styleScheme): void
    {
        Symbols::get()->gtk_source_buffer_set_style_scheme($this->handle, $styleScheme);
    }

    public function getStyleScheme(): ?\FFI\CData
    {
        $result = Symbols::get()->gtk_source_buffer_get_style_scheme($this->handle);
        return $result;
    }
}
