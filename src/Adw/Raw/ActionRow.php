<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Adw-1.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Adw\Raw;

class ActionRow extends \Cyonima\Adw\Raw\PreferencesRow
{
    public const TYPE_NAME = 'AdwActionRow';

    public const SIGNAL_ACTIVATED = 'activated';

    public static function new(): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->adw_action_row_new();
        return new self(self::adopt($pointer));
    }

    public function setSubtitle(?string $subtitle): void
    {
        Symbols::get()->adw_action_row_set_subtitle($this->handle, $subtitle);
    }

    public function getSubtitle(): ?string
    {
        $result = Symbols::get()->adw_action_row_get_subtitle($this->handle);
        return $result;
    }

    public function setSubtitleLines(int $subtitleLines): void
    {
        Symbols::get()->adw_action_row_set_subtitle_lines($this->handle, $subtitleLines);
    }

    public function setIconName(?string $iconName): void
    {
        Symbols::get()->adw_action_row_set_icon_name($this->handle, $iconName);
    }

    public function addSuffix(?\Cyonima\Gtk\Raw\Widget $suffix): void
    {
        Symbols::get()->adw_action_row_add_suffix($this->handle, $suffix?->handle());
    }

    public function onActivated(callable $handler): int
    {
        return $this->connectSignal(self::SIGNAL_ACTIVATED, $handler, [], true);
    }
}
