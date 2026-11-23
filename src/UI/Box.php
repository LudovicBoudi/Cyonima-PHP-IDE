<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw;

final class Box extends Widget
{
    public function __construct(int $orientation = Raw\Orientation::VERTICAL, int $spacing = 0)
    {
        parent::__construct(Raw\Box::new($orientation, $spacing));
    }

    public function raw(): Raw\Box
    {
        /** @var Raw\Box $raw */
        $raw = $this->raw;
        return $raw;
    }

    public function append(Widget ...$children): static
    {
        foreach ($children as $child) {
            $this->raw()->append($child->raw());
        }
        return $this;
    }

    public function remove(Widget ...$children): static
    {
        foreach ($children as $child) {
            $this->raw()->remove($child->raw());
        }
        return $this;
    }

    public function spacing(int $spacing): static
    {
        $this->raw()->setSpacing($spacing);
        return $this;
    }

    public function homogeneous(bool $homogeneous = true): static
    {
        $this->raw()->setHomogeneous($homogeneous);
        return $this;
    }
}
