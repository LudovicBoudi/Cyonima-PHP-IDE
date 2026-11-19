<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw\ScrolledWindow as RawScrolledWindow;

final class ScrolledWindow extends Widget
{
    public function __construct(?Widget $child = null)
    {
        parent::__construct(RawScrolledWindow::new());

        if ($child !== null) {
            $this->raw()->setChild($child->raw());
        }
    }

    public function raw(): RawScrolledWindow
    {
        /** @var RawScrolledWindow $raw */
        $raw = $this->raw;
        return $raw;
    }

    public function child(Widget $child): static
    {
        $this->raw()->setChild($child->raw());
        return $this;
    }
}
