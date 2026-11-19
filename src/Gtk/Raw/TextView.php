<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Gtk-4.0.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Gtk\Raw;

class TextView extends \Cyonima\Gtk\Raw\Widget
{
    public const TYPE_NAME = 'GtkTextView';

    public function getBuffer(): ?\Cyonima\Gtk\Raw\TextBuffer
    {
        $result = Symbols::get()->gtk_text_view_get_buffer($this->handle);
        return $result === null ? null : \Cyonima\Gtk\Raw\TextBuffer::wrap($result, false);
    }

    public function setMonospace(bool $monospace): void
    {
        Symbols::get()->gtk_text_view_set_monospace($this->handle, ($monospace) ? 1 : 0);
    }

    public function setEditable(bool $editable): void
    {
        Symbols::get()->gtk_text_view_set_editable($this->handle, ($editable) ? 1 : 0);
    }
}
