<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw;

final class Label extends Widget
{
    public function __construct(string $text = '')
    {
        parent::__construct(Raw\Label::new($text));
    }

    public function raw(): Raw\Label
    {
        /** @var Raw\Label $raw */
        $raw = $this->raw;
        return $raw;
    }

    public function text(string $text): static
    {
        $this->raw()->setText($text);
        return $this;
    }

    public function getText(): string
    {
        return (string) $this->raw()->getText();
    }

    /** Interprète le contenu comme du balisage Pango (gras, italique, taille…). */
    public function markup(string $markup): static
    {
        $this->raw()->setMarkup($markup);
        return $this;
    }

    public function selectable(bool $selectable = true): static
    {
        $this->raw()->setSelectable($selectable);
        return $this;
    }

    public function xalign(float $align): static
    {
        $this->raw()->setXalign($align);
        return $this;
    }

    public function wrap(bool $wrap = true): static
    {
        $this->raw()->setWrap($wrap);
        return $this;
    }
}
