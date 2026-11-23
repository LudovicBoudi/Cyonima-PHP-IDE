<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw;

/**
 * Façade orientée objet au-dessus des bindings bruts.
 *
 * C'est la couche que le code applicatif manipule : noms courts, chaînage,
 * typage strict. Aucun FFI ici — tout passe par `Cyonima\Gtk\Raw` (règle R3).
 */
abstract class Widget
{
    public function __construct(protected readonly Raw\Widget $raw)
    {
    }

    public function raw(): Raw\Widget
    {
        return $this->raw;
    }

    public function cssClass(string $class): static
    {
        $this->raw->addCssClass($class);
        return $this;
    }

    public function removeCssClass(string $class): static
    {
        $this->raw->removeCssClass($class);
        return $this;
    }

    public function margin(int $all): static
    {
        $this->raw->setMarginTop($all);
        $this->raw->setMarginBottom($all);
        $this->raw->setMarginStart($all);
        $this->raw->setMarginEnd($all);
        return $this;
    }

    public function marginTop(int $margin): static
    {
        $this->raw->setMarginTop($margin);
        return $this;
    }

    public function align(int $align): static
    {
        $this->raw->setHalign($align);
        $this->raw->setValign($align);
        return $this;
    }

    public function expand(bool $horizontal = true, bool $vertical = true): static
    {
        $this->raw->setHexpand($horizontal);
        $this->raw->setVexpand($vertical);
        return $this;
    }

    public function visible(bool $visible = true): static
    {
        $this->raw->setVisible($visible);
        return $this;
    }

    public function tooltip(string $text): static
    {
        $this->raw->setTooltipText($text);
        return $this;
    }

    public function grabFocus(): static
    {
        $this->raw->grabFocus();
        return $this;
    }

    public function width(): int
    {
        return $this->raw->getWidth();
    }

    public function height(): int
    {
        return $this->raw->getHeight();
    }
}
