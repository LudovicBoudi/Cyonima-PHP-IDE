<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Adw-1.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Adw\Raw;

class ApplicationWindow extends \Cyonima\Gtk\Raw\ApplicationWindow
{
    public const TYPE_NAME = 'AdwApplicationWindow';

    public static function create(?\Cyonima\Gtk\Raw\Application $application): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->adw_application_window_new($application?->handle());
        return new self(self::adopt($pointer));
    }

    public function setContent(?\Cyonima\Gtk\Raw\Widget $content): void
    {
        Symbols::get()->adw_application_window_set_content($this->handle, $content?->handle());
    }

    public function getContent(): ?\Cyonima\Gtk\Raw\Widget
    {
        $result = Symbols::get()->adw_application_window_get_content($this->handle);
        return $result === null ? null : \Cyonima\Gtk\Raw\Widget::wrap($result, false);
    }
}
