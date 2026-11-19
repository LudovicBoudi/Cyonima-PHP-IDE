<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw;

final class Button extends Widget
{
    public function __construct(string $label = '')
    {
        parent::__construct(Raw\Button::newWithLabel($label));
    }

    public function raw(): Raw\Button
    {
        /** @var Raw\Button $raw */
        $raw = $this->raw;
        return $raw;
    }

    public function label(string $label): static
    {
        $this->raw()->setLabel($label);
        return $this;
    }

    public function getLabel(): string
    {
        return (string) $this->raw()->getLabel();
    }

    /** Le handler reçoit le bouton façade. */
    public function onClicked(callable $handler): static
    {
        $this->raw()->onClicked(fn () => $handler($this));
        return $this;
    }

    /** Émission programmatique du clic (tests, scénarios). */
    public function click(): static
    {
        $this->raw()->emit(Raw\Button::SIGNAL_CLICKED);
        return $this;
    }
}
