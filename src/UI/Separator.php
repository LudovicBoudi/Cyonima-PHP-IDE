<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw\Orientation;
use Cyonima\Gtk\Raw\Separator as RawSeparator;

/** Séparateur horizontal ou vertical. */
final class Separator extends Widget
{
    public function __construct(int $orientation = Orientation::HORIZONTAL)
    {
        parent::__construct(RawSeparator::new($orientation));
    }

    public function raw(): RawSeparator
    {
        /** @var RawSeparator $raw */
        $raw = $this->raw;
        return $raw;
    }
}
