<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Adw-1.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Adw\Raw;

class ToolbarView extends \Cyonima\Gtk\Raw\Widget
{
    public const TYPE_NAME = 'AdwToolbarView';

    public static function new(): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->adw_toolbar_view_new();
        return new self(self::adopt($pointer));
    }

    public function addTopBar(?\Cyonima\Gtk\Raw\Widget $widget): void
    {
        Symbols::get()->adw_toolbar_view_add_top_bar($this->handle, $widget?->handle());
    }

    public function addBottomBar(?\Cyonima\Gtk\Raw\Widget $widget): void
    {
        Symbols::get()->adw_toolbar_view_add_bottom_bar($this->handle, $widget?->handle());
    }

    public function setContent(?\Cyonima\Gtk\Raw\Widget $content): void
    {
        Symbols::get()->adw_toolbar_view_set_content($this->handle, $content?->handle());
    }

    public function getContent(): ?\Cyonima\Gtk\Raw\Widget
    {
        $result = Symbols::get()->adw_toolbar_view_get_content($this->handle);
        return $result === null ? null : \Cyonima\Gtk\Raw\Widget::wrap($result, false);
    }
}
