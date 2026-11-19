<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Gtk-4.0.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Gtk\Raw;

class Widget extends RawObject
{
    public const TYPE_NAME = 'GtkWidget';

    public function addCssClass(?string $cssClass): void
    {
        Symbols::get()->gtk_widget_add_css_class($this->handle, $cssClass);
    }

    public function removeCssClass(?string $cssClass): void
    {
        Symbols::get()->gtk_widget_remove_css_class($this->handle, $cssClass);
    }

    public function setVisible(bool $visible): void
    {
        Symbols::get()->gtk_widget_set_visible($this->handle, ($visible) ? 1 : 0);
    }

    public function setSensitive(bool $sensitive): void
    {
        Symbols::get()->gtk_widget_set_sensitive($this->handle, ($sensitive) ? 1 : 0);
    }

    public function setHexpand(bool $expand): void
    {
        Symbols::get()->gtk_widget_set_hexpand($this->handle, ($expand) ? 1 : 0);
    }

    public function setVexpand(bool $expand): void
    {
        Symbols::get()->gtk_widget_set_vexpand($this->handle, ($expand) ? 1 : 0);
    }

    public function setHalign(int $align): void
    {
        Symbols::get()->gtk_widget_set_halign($this->handle, $align);
    }

    public function setValign(int $align): void
    {
        Symbols::get()->gtk_widget_set_valign($this->handle, $align);
    }

    public function setMarginTop(int $margin): void
    {
        Symbols::get()->gtk_widget_set_margin_top($this->handle, $margin);
    }

    public function setMarginBottom(int $margin): void
    {
        Symbols::get()->gtk_widget_set_margin_bottom($this->handle, $margin);
    }

    public function setMarginStart(int $margin): void
    {
        Symbols::get()->gtk_widget_set_margin_start($this->handle, $margin);
    }

    public function setMarginEnd(int $margin): void
    {
        Symbols::get()->gtk_widget_set_margin_end($this->handle, $margin);
    }

    public function setSizeRequest(int $width, int $height): void
    {
        Symbols::get()->gtk_widget_set_size_request($this->handle, $width, $height);
    }

    public function setTooltipText(?string $text): void
    {
        Symbols::get()->gtk_widget_set_tooltip_text($this->handle, $text);
    }

    public function getDisplay(): ?\FFI\CData
    {
        $result = Symbols::get()->gtk_widget_get_display($this->handle);
        return $result;
    }

    public function getWidth(): int
    {
        $result = Symbols::get()->gtk_widget_get_width($this->handle);
        return $result;
    }

    public function getHeight(): int
    {
        $result = Symbols::get()->gtk_widget_get_height($this->handle);
        return $result;
    }

    public function getParent(): ?\Cyonima\Gtk\Raw\Widget
    {
        $result = Symbols::get()->gtk_widget_get_parent($this->handle);
        return $result === null ? null : \Cyonima\Gtk\Raw\Widget::wrap($result, false);
    }

    public function getVisible(): bool
    {
        $result = Symbols::get()->gtk_widget_get_visible($this->handle);
        return ($result) !== 0;
    }

    public function addController(?\Cyonima\Gtk\Raw\EventController $controller): void
    {
        Symbols::get()->gtk_widget_add_controller($this->handle, $controller?->handle());
    }

    public function setParent(?\Cyonima\Gtk\Raw\Widget $parent): void
    {
        Symbols::get()->gtk_widget_set_parent($this->handle, $parent?->handle());
    }
}
