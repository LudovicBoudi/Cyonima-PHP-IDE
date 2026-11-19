<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw;

final class Window extends Widget
{
    public function __construct(string $title = '', int $width = 900, int $height = 600)
    {
        parent::__construct(Raw\Window::new());
        $this->raw()->setTitle($title);
        $this->raw()->setDefaultSize($width, $height);
    }

    public function raw(): Raw\Window
    {
        /** @var Raw\Window $raw */
        $raw = $this->raw;
        return $raw;
    }

    public function setChild(?Widget $child): static
    {
        $this->raw()->setChild($child?->raw());
        return $this;
    }

    public function title(string $title): static
    {
        $this->raw()->setTitle($title);
        return $this;
    }

    /** Le handler reçoit la fenêtre façade, pas le wrapper brut. */
    public function onCloseRequest(callable $handler): static
    {
        $this->raw()->onCloseRequest(fn () => $handler($this));
        return $this;
    }

    public function present(): static
    {
        $this->raw()->present();
        return $this;
    }

    public function resize(int $width, int $height): static
    {
        $this->raw()->setDefaultSize($width, $height);
        return $this;
    }

    /**
     * Applique une feuille CSS à toute l'application.
     *
     * Le provider est référencé par le display : le wrapper PHP peut mourir,
     * la feuille reste active.
     */
    public function addCss(string $css): static
    {
        $provider = Raw\CssProvider::new();
        $provider->loadFromString($css);

        $display = $this->raw()->getDisplay();
        if ($display !== null) {
            // GtkStyleProvider est une interface non générée : on passe le
            // pointeur brut du CssProvider (type opaque côté bindings).
            Raw\StyleContext::addProviderForDisplay($display, $provider->handle(), 600);
        }

        return $this;
    }
}
