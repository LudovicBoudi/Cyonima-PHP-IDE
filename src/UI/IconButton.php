<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw\Button as RawButton;

/** Bouton icône plat, pour barres d'activité et barres d'outils. */
final class IconButton extends Widget
{
    public function __construct(string $iconName, string $tooltip = '')
    {
        parent::__construct(RawButton::newFromIconName($iconName));
        $this->raw()->setHasFrame(false);

        if ($tooltip !== '') {
            $this->raw()->setTooltipText($tooltip);
        }
    }

    public function raw(): RawButton
    {
        /** @var RawButton $raw */
        $raw = $this->raw;
        return $raw;
    }

    public function onClicked(callable $handler): static
    {
        $this->raw()->onClicked(fn () => $handler($this));
        return $this;
    }

    public function click(): static
    {
        $this->raw()->emit(RawButton::SIGNAL_CLICKED);
        return $this;
    }
}
