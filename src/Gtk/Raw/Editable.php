<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Gtk-4.0.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Gtk\Raw;

class Editable extends RawObject
{
    public const TYPE_NAME = 'Editable';

    public const SIGNAL_CHANGED = 'changed';

    public function getText(): ?string
    {
        $result = Symbols::get()->gtk_editable_get_text($this->handle);
        return $result;
    }

    public function setText(?string $text): void
    {
        Symbols::get()->gtk_editable_set_text($this->handle, $text);
    }

    public function onChanged(callable $handler): int
    {
        return $this->connectSignal(self::SIGNAL_CHANGED, $handler, [], true);
    }
}
