<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Gtk-4.0.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Gtk\Raw;

class StyleContext extends RawObject
{
    public const TYPE_NAME = 'GtkStyleContext';

    public static function addProviderForDisplay(?\FFI\CData $display, ?\FFI\CData $provider, int $priority): void
    {
        Symbols::get()->gtk_style_context_add_provider_for_display($display, $provider, $priority);
    }

    public static function removeProviderForDisplay(?\FFI\CData $display, ?\FFI\CData $provider): void
    {
        Symbols::get()->gtk_style_context_remove_provider_for_display($display, $provider);
    }
}
