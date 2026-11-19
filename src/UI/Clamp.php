<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Adw\Raw\Clamp as RawClamp;

/** Centre le contenu et limite sa largeur (layout Adwaita). */
final class Clamp extends Widget
{
    public function __construct(?Widget $content = null, int $maximumSize = 600)
    {
        parent::__construct(RawClamp::new());
        $this->raw()->setMaximumSize($maximumSize);
        $this->raw()->setTighteningThreshold(max(1, intdiv($maximumSize, 2)));

        if ($content !== null) {
            $this->raw()->setChild($content->raw());
        }
    }

    public function raw(): RawClamp
    {
        /** @var RawClamp $raw */
        $raw = $this->raw;
        return $raw;
    }

    public function maximumSize(int $size): static
    {
        $this->raw()->setMaximumSize($size);
        return $this;
    }

    public function child(Widget $content): static
    {
        $this->raw()->setChild($content->raw());
        return $this;
    }
}
