<?php

declare(strict_types=1);

/**
 * Fichier généré depuis GtkSource-5.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\GtkSource\Raw;

class StyleSchemeManager extends \Cyonima\Gtk\Raw\RawObject
{
    public const TYPE_NAME = 'GtkSourceStyleSchemeManager';

    public static function getDefault(): ?\Cyonima\GtkSource\Raw\StyleSchemeManager
    {
        $result = Symbols::get()->gtk_source_style_scheme_manager_get_default();
        return $result === null ? null : \Cyonima\GtkSource\Raw\StyleSchemeManager::wrap($result, false);
    }

    public function getScheme(?string $schemeId): ?\FFI\CData
    {
        $result = Symbols::get()->gtk_source_style_scheme_manager_get_scheme($this->handle, $schemeId);
        return $result;
    }
}
