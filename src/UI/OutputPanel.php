<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw\Box as RawBox;
use Cyonima\Gtk\Raw\Label as RawLabel;
use Cyonima\Gtk\Raw\Orientation;
use Cyonima\Gtk\Raw\ScrolledWindow as RawScrolledWindow;
use Cyonima\Runtime\GtkScroll;
use Cyonima\Runtime\Loop;
use Cyonima\Runtime\OutputLog;

/**
 * Panneau SORTIE : journal horodaté, canal optionnel, défilement automatique
 * vers le bas et bouton de vidage.
 */
final class OutputPanel extends Widget
{
    private OutputLog $log;
    private RawLabel $label;
    private RawScrolledWindow $scroller;

    public function __construct(?OutputLog $log = null)
    {
        parent::__construct(RawBox::new(Orientation::VERTICAL, 0));

        $this->log = $log ?? new OutputLog();

        $clear = (new Button('Vider'))
            ->cssClass('output-clear')
            ->onClicked(fn (): static => $this->clear());

        $toolbar = (new Box(Orientation::HORIZONTAL, 4))
            ->cssClass('output-toolbar')
            ->append($clear);

        $this->label = RawLabel::new('');
        $this->label->setXalign(0.0);
        $this->label->setWrap(true);
        $this->label->setSelectable(true);
        $this->label->addCssClass('output-text');

        $this->scroller = RawScrolledWindow::new();
        $this->scroller->setChild($this->label);
        $this->scroller->setVexpand(true);

        $this->raw()->append($toolbar->raw());
        $this->raw()->append($this->scroller);
    }

    public function raw(): RawBox
    {
        /** @var RawBox $raw */
        $raw = $this->raw;
        return $raw;
    }

    public function append(string $message, string $channel = ''): static
    {
        $this->log->append($message, $channel);
        $this->label->setText($this->log->text());
        $this->scrollToBottom();

        return $this;
    }

    public function clear(): static
    {
        $this->log->clear();
        $this->label->setText('');

        return $this;
    }

    public function text(): string
    {
        return $this->log->text();
    }

    public function count(): int
    {
        return $this->log->count();
    }

    public function log(): OutputLog
    {
        return $this->log;
    }

    private function scrollToBottom(): void
    {
        GtkScroll::toBottom($this->scroller->handle());

        // La hauteur du label est recalculée au prochain cycle de layout.
        Loop::after(0, function (): void {
            GtkScroll::toBottom($this->scroller->handle());
        });
    }
}
