<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Adw\Raw\SwitchRow as RawSwitchRow;

final class SwitchRow extends Widget
{
    public function __construct(string $title = '', string $subtitle = '', bool $active = false)
    {
        parent::__construct(RawSwitchRow::new());

        if ($title !== '') {
            $this->raw()->setTitle($title);
        }
        if ($subtitle !== '') {
            $this->raw()->setSubtitle($subtitle);
        }
        $this->raw()->setActive($active);
    }

    public function raw(): RawSwitchRow
    {
        /** @var RawSwitchRow $raw */
        $raw = $this->raw;
        return $raw;
    }

    public function active(bool $active): static
    {
        $this->raw()->setActive($active);
        return $this;
    }

    public function isActive(): bool
    {
        return $this->raw()->getActive();
    }

    /**
     * Adw.SwitchRow n'expose pas de signal dédié : on écoute la propriété
     * `active` via `notify::active` (le GParamSpec est ignoré).
     */
    public function onChanged(callable $handler): static
    {
        $this->raw()->connectSignal('notify::active', fn () => $handler($this), 1);
        return $this;
    }
}
