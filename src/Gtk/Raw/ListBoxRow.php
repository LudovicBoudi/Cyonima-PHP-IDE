<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Gtk-4.0.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Gtk\Raw;

class ListBoxRow extends \Cyonima\Gtk\Raw\Widget
{
    public const TYPE_NAME = 'GtkListBoxRow';

    public const SIGNAL_ACTIVATE = 'activate';

    public static function new(): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->gtk_list_box_row_new();
        return new self(self::adopt($pointer));
    }

    public function setChild(?\Cyonima\Gtk\Raw\Widget $child): void
    {
        Symbols::get()->gtk_list_box_row_set_child($this->handle, $child?->handle());
    }

    public function getChild(): ?\Cyonima\Gtk\Raw\Widget
    {
        $result = Symbols::get()->gtk_list_box_row_get_child($this->handle);
        return $result === null ? null : \Cyonima\Gtk\Raw\Widget::wrap($result, false);
    }

    public function setActivatable(bool $activatable): void
    {
        Symbols::get()->gtk_list_box_row_set_activatable($this->handle, ($activatable) ? 1 : 0);
    }

    public function setSelectable(bool $selectable): void
    {
        Symbols::get()->gtk_list_box_row_set_selectable($this->handle, ($selectable) ? 1 : 0);
    }

    public function onActivate(callable $handler): int
    {
        return $this->connectSignal(self::SIGNAL_ACTIVATE, $handler, [], true);
    }
}
