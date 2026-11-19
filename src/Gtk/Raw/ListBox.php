<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Gtk-4.0.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Gtk\Raw;

class ListBox extends \Cyonima\Gtk\Raw\Widget
{
    public const TYPE_NAME = 'GtkListBox';

    public static function new(): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->gtk_list_box_new();
        return new self(self::adopt($pointer));
    }

    public function append(?\Cyonima\Gtk\Raw\Widget $child): void
    {
        Symbols::get()->gtk_list_box_append($this->handle, $child?->handle());
    }

    public function remove(?\Cyonima\Gtk\Raw\Widget $child): void
    {
        Symbols::get()->gtk_list_box_remove($this->handle, $child?->handle());
    }

    public function removeAll(): void
    {
        Symbols::get()->gtk_list_box_remove_all($this->handle);
    }

    public function selectRow(?\Cyonima\Gtk\Raw\ListBoxRow $row): void
    {
        Symbols::get()->gtk_list_box_select_row($this->handle, $row?->handle());
    }

    public function getSelectedRow(): ?\Cyonima\Gtk\Raw\ListBoxRow
    {
        $result = Symbols::get()->gtk_list_box_get_selected_row($this->handle);
        return $result === null ? null : \Cyonima\Gtk\Raw\ListBoxRow::wrap($result, false);
    }

    public function setSelectionMode(int $mode): void
    {
        Symbols::get()->gtk_list_box_set_selection_mode($this->handle, $mode);
    }
}
