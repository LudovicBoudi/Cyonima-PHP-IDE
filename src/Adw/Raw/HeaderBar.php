<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Adw-1.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Adw\Raw;

class HeaderBar extends \Cyonima\Gtk\Raw\Widget
{
    public const TYPE_NAME = 'AdwHeaderBar';

    public static function new(): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->adw_header_bar_new();
        return new self(self::adopt($pointer));
    }

    public function packStart(?\Cyonima\Gtk\Raw\Widget $child): void
    {
        Symbols::get()->adw_header_bar_pack_start($this->handle, $child?->handle());
    }

    public function packEnd(?\Cyonima\Gtk\Raw\Widget $child): void
    {
        Symbols::get()->adw_header_bar_pack_end($this->handle, $child?->handle());
    }

    public function setTitleWidget(?\Cyonima\Gtk\Raw\Widget $titleWidget): void
    {
        Symbols::get()->adw_header_bar_set_title_widget($this->handle, $titleWidget?->handle());
    }

    public function setShowBackButton(bool $showBackButton): void
    {
        Symbols::get()->adw_header_bar_set_show_back_button($this->handle, ($showBackButton) ? 1 : 0);
    }

    public function setShowStartTitleButtons(bool $showStartTitleButtons): void
    {
        Symbols::get()->adw_header_bar_set_show_start_title_buttons($this->handle, ($showStartTitleButtons) ? 1 : 0);
    }

    public function setShowEndTitleButtons(bool $showEndTitleButtons): void
    {
        Symbols::get()->adw_header_bar_set_show_end_title_buttons($this->handle, ($showEndTitleButtons) ? 1 : 0);
    }

    public function setCenteringPolicy(int $centeringPolicy): void
    {
        Symbols::get()->adw_header_bar_set_centering_policy($this->handle, $centeringPolicy);
    }
}
