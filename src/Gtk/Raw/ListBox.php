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

    public const SIGNAL_ROW_ACTIVATED = 'row-activated';

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

    public function getActivateOnSingleClick(): bool
    {
        $result = Symbols::get()->gtk_list_box_get_activate_on_single_click($this->handle);
        return ($result) !== 0;
    }

    public function setActivateOnSingleClick(bool $activateOnSingleClick): void
    {
        Symbols::get()->gtk_list_box_set_activate_on_single_click($this->handle, ($activateOnSingleClick) ? 1 : 0);
    }

    public function onRowActivated(callable $handler): int
    {
        return $this->connectSignal(self::SIGNAL_ROW_ACTIVATED, $handler, ['void*'], true);
    }
}
