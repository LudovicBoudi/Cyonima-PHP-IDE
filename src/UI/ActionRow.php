<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Adw\Raw\ActionRow as RawActionRow;

final class ActionRow extends Widget
{
    public function __construct(string $title = '', string $subtitle = '')
    {
        parent::__construct(RawActionRow::new());

        if ($title !== '') {
            $this->raw()->setTitle($title);
        }
        if ($subtitle !== '') {
            $this->raw()->setSubtitle($subtitle);
        }
    }

    public function raw(): RawActionRow
    {
        /** @var RawActionRow $raw */
        $raw = $this->raw;
        return $raw;
    }

    public function title(string $title): static
    {
        $this->raw()->setTitle($title);
        return $this;
    }

    public function subtitle(string $subtitle): static
    {
        $this->raw()->setSubtitle($subtitle);
        return $this;
    }

    public function icon(string $iconName): static
    {
        $this->raw()->setIconName($iconName);
        return $this;
    }

    public function suffix(Widget ...$widgets): static
    {
        foreach ($widgets as $widget) {
            $this->raw()->addSuffix($widget->raw());
        }
        return $this;
    }

    /** Le handler reçoit la ligne façade. */
    public function onActivated(callable $handler): static
    {
        $this->raw()->onActivated(fn () => $handler($this));
        return $this;
    }
}
