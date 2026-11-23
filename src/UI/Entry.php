<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw;

/** Champ de saisie simple, avec texte d'invite et signaux activate/changed. */
final class Entry extends Widget
{
    public function __construct(string $placeholder = '', string $text = '')
    {
        parent::__construct(Raw\Entry::new());

        if ($placeholder !== '') {
            $this->raw()->setPlaceholderText($placeholder);
        }

        if ($text !== '') {
            $this->setText($text);
        }
    }

    public function raw(): Raw\Entry
    {
        /** @var Raw\Entry $raw */
        $raw = $this->raw;
        return $raw;
    }

    /** `GtkEditable` est une interface : on enveloppe le même GObject. */
    private function editable(): Raw\Editable
    {
        return Raw\Editable::wrap($this->raw()->handle(), false);
    }

    public function text(): string
    {
        return (string) $this->editable()->getText();
    }

    public function setText(string $text): static
    {
        $this->editable()->setText($text);
        return $this;
    }

    public function placeholder(string $text): static
    {
        $this->raw()->setPlaceholderText($text);
        return $this;
    }

    /** Le handler reçoit le champ façade. */
    public function onActivate(callable $handler): static
    {
        $this->raw()->onActivate(fn () => $handler($this));
        return $this;
    }

    /** Le handler reçoit le champ façade à chaque modification du texte. */
    public function onChanged(callable $handler): static
    {
        $this->editable()->onChanged(fn () => $handler($this));
        return $this;
    }
}
