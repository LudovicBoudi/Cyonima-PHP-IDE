<?php

declare(strict_types=1);

/**
 * Fichier généré depuis GtkSource-5.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\GtkSource\Raw;

class LanguageManager extends \Cyonima\Gtk\Raw\RawObject
{
    public const TYPE_NAME = 'GtkSourceLanguageManager';

    public static function getDefault(): ?\Cyonima\GtkSource\Raw\LanguageManager
    {
        $result = Symbols::get()->gtk_source_language_manager_get_default();
        return $result === null ? null : \Cyonima\GtkSource\Raw\LanguageManager::wrap($result, false);
    }

    public function getLanguage(?string $languageId): ?\FFI\CData
    {
        $result = Symbols::get()->gtk_source_language_manager_get_language($this->handle, $languageId);
        return $result;
    }
}
