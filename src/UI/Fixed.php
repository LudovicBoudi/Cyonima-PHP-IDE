<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw\Fixed as RawFixed;

/** Conteneur à positionnement absolu, utilisé par le concepteur de formulaires. */
final class Fixed extends Widget
{
    public function __construct(int $width = 720, int $height = 480)
    {
        parent::__construct(RawFixed::new());
        $this->raw()->setSizeRequest($width, $height);
    }

    public function raw(): RawFixed
    {
        /** @var RawFixed $raw */
        $raw = $this->raw;
        return $raw;
    }

    public function put(Widget $child, int|float $x, int|float $y): static
    {
        $this->raw()->put($child->raw(), (float) $x, (float) $y);
        return $this;
    }

    public function move(Widget $child, int|float $x, int|float $y): static
    {
        $this->raw()->move($child->raw(), (float) $x, (float) $y);
        return $this;
    }

    public function remove(Widget $child): static
    {
        $this->raw()->remove($child->raw());
        return $this;
    }

    public function size(int $width, int $height): static
    {
        $this->raw()->setSizeRequest($width, $height);
        return $this;
    }
}
