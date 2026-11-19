<?php

declare(strict_types=1);

/**
 * Fichier généré depuis Gtk-4.0.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\Gtk\Raw;

class Window extends \Cyonima\Gtk\Raw\Widget
{
    public const TYPE_NAME = 'GtkWindow';

    public const SIGNAL_CLOSE_REQUEST = 'close-request';

    public static function new(): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->gtk_window_new();
        return new self(self::adopt($pointer));
    }

    public function setTitle(?string $title): void
    {
        Symbols::get()->gtk_window_set_title($this->handle, $title);
    }

    public function getTitle(): ?string
    {
        $result = Symbols::get()->gtk_window_get_title($this->handle);
        return $result;
    }

    public function setDefaultSize(int $width, int $height): void
    {
        Symbols::get()->gtk_window_set_default_size($this->handle, $width, $height);
    }

    public function setChild(?\Cyonima\Gtk\Raw\Widget $child): void
    {
        Symbols::get()->gtk_window_set_child($this->handle, $child?->handle());
    }

    public function getChild(): ?\Cyonima\Gtk\Raw\Widget
    {
        $result = Symbols::get()->gtk_window_get_child($this->handle);
        return $result === null ? null : \Cyonima\Gtk\Raw\Widget::wrap($result, false);
    }

    public function present(): void
    {
        Symbols::get()->gtk_window_present($this->handle);
    }

    public function close(): void
    {
        Symbols::get()->gtk_window_close($this->handle);
    }

    public function destroy(): void
    {
        Symbols::get()->gtk_window_destroy($this->handle);
    }

    public function setResizable(bool $resizable): void
    {
        Symbols::get()->gtk_window_set_resizable($this->handle, ($resizable) ? 1 : 0);
    }

    public function setDecorated(bool $decorated): void
    {
        Symbols::get()->gtk_window_set_decorated($this->handle, ($decorated) ? 1 : 0);
    }

    public function setModal(bool $modal): void
    {
        Symbols::get()->gtk_window_set_modal($this->handle, ($modal) ? 1 : 0);
    }

    public function maximize(): void
    {
        Symbols::get()->gtk_window_maximize($this->handle);
    }

    public function onCloseRequest(callable $handler): int
    {
        return $this->connectSignal(self::SIGNAL_CLOSE_REQUEST, $handler, 0);
    }
}
