<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw\Orientation;
use Cyonima\Gtk\Raw\Paned as RawPaned;

/** Séparateur redimensionnable entre deux zones. */
final class Paned extends Widget
{
    public function __construct(int $orientation = Orientation::VERTICAL, int $position = 0)
    {
        parent::__construct(RawPaned::new($orientation));
        $this->raw()->setPosition($position);
        $this->raw()->setResizeStartChild(true);
        $this->raw()->setShrinkStartChild(false);
    }

    public function raw(): RawPaned
    {
        /** @var RawPaned $raw */
        $raw = $this->raw;
        return $raw;
    }

    public function start(Widget $child): static
    {
        $this->raw()->setStartChild($child->raw());
        return $this;
    }

    public function end(Widget $child): static
    {
        $this->raw()->setEndChild($child->raw());
        return $this;
    }

    public function position(int $pixels): static
    {
        $this->raw()->setPosition($pixels);
        return $this;
    }
}
