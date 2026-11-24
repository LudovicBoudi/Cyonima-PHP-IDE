<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw\Box as RawBox;
use Cyonima\Gtk\Raw\EventControllerMotion as RawEventControllerMotion;
use Cyonima\Gtk\Raw\Orientation;
use Cyonima\Gtk\Raw\Popover as RawPopover;
use Cyonima\Runtime\GtkRect;
use FFI;

/**
 * Barre de menus déroulants (Fichier, Édition…).
 *
 * Chaque menu est un `GtkPopover` ancré à son bouton. Les actions sont des
 * closures : aucune table d'actions globale n'est nécessaire pour cette UI.
 */
final class MenuBar extends Widget
{
    /** @var list<RawPopover> */
    private array $popovers = [];

    /** @var list<Widget> */
    private array $keepAlive = [];

    /** @var list<RawEventControllerMotion> */
    private array $motionControllers = [];

    private ?RawPopover $openPopover = null;

    public function __construct()
    {
        parent::__construct(RawBox::new(Orientation::HORIZONTAL, 0));
        $this->raw()->addCssClass('menu-bar');
    }

    public function raw(): RawBox
    {
        /** @var RawBox $raw */
        $raw = $this->raw;
        return $raw;
    }

    public function __destruct()
    {
        // Un popover est parenté à son bouton : le détacher évite que GTK
        // finalise le bouton avec un enfant encore attaché.
        foreach ($this->popovers as $popover) {
            if ($popover->getVisible()) {
                $popover->popdown();
            }
            $popover->unparent();
        }
    }

    /**
     * @param list<array{label?: string, shortcut?: string, action?: callable, separator?: bool}> $items
     */
    public function addMenu(string $title, array $items): static
    {
        $trigger = (new Button())
            ->cssClass('menu-trigger')
            ->child((new Label($title))->xalign(0.0));

        $popover = RawPopover::new();
        $popover->setHasArrow(false);
        $popover->setAutohide(true);
        $popover->setParent($trigger->raw());

        $self = \WeakReference::create($this);

        $popover->onClosed(static function () use ($popover, $self): void {
            $menu = $self->get();
            if ($menu instanceof self) {
                $menu->handlePopoverClosed($popover);
            }
        });

        $content = (new Box(Orientation::VERTICAL, 0))->cssClass('menu-popover');
        $content->raw()->setSizeRequest(280, -1);

        foreach ($items as $item) {
            if ($item['separator'] ?? false) {
                $content->append((new Box(Orientation::VERTICAL, 0))->cssClass('menu-separator'));
                continue;
            }

            $label = (new Label($item['label'] ?? ''))->xalign(0.0)->expand(true, false);
            $row = (new Box(Orientation::HORIZONTAL, 16))
                ->cssClass('menu-entry')
                ->append($label);

            $shortcut = $item['shortcut'] ?? '';
            if ($shortcut !== '') {
                $row->append((new Label($shortcut))->cssClass('menu-shortcut'));
            }

            $action = $item['action'] ?? static fn (): null => null;
            $button = (new Button())
                ->cssClass('menu-action')
                ->child($row)
                ->expand(true, false)
                ->onClicked(function () use ($popover, $action): void {
                    $popover->popdown();
                    $action();
                });

            $content->append($button);
            $this->keepAlive[] = $button;
        }

        $popover->setChild($content->raw());
        $this->popovers[] = $popover;
        $this->keepAlive[] = $trigger;

        $trigger->onClicked(static function () use ($popover, $trigger, $self): void {
            $menu = $self->get();
            if ($menu instanceof self) {
                $menu->handleTriggerClick($popover, $trigger);
            }
        });

        // Comme dans Visual Studio : survoler un autre titre bascule le menu
        // sans devoir cliquer hors du menu courant.
        $motion = RawEventControllerMotion::new();
        // Synchrone : le basculement au survol doit être immédiat.
        $motion->connectSignal(
            RawEventControllerMotion::SIGNAL_ENTER,
            static function () use ($popover, $trigger, $self): void {
                $menu = $self->get();
                if ($menu instanceof self) {
                    $menu->handleTriggerEnter($popover, $trigger);
                }
            },
            ['double', 'double'],
            false,
        );
        $trigger->raw()->addController($motion);
        $this->motionControllers[] = $motion;

        $this->raw()->append($trigger->raw());

        return $this;
    }

    private function handleTriggerClick(RawPopover $popover, Button $trigger): void
    {
        if ($this->openPopover === $popover) {
            $popover->popdown();
            return;
        }

        $this->showPopover($popover, $trigger);
    }

    private function handleTriggerEnter(RawPopover $popover, Button $trigger): void
    {
        if ($this->openPopover !== null && $this->openPopover !== $popover) {
            $this->showPopover($popover, $trigger);
        }
    }

    private function handlePopoverClosed(RawPopover $popover): void
    {
        if ($this->openPopover === $popover) {
            $this->openPopover = null;
        }
    }

    private function showPopover(RawPopover $popover, Button $trigger): void
    {
        $previous = $this->openPopover;
        $this->openPopover = $popover;

        $previous?->popdown();

        // Sans rectangle de visée, le popover se place au hasard (souvent
        // hors écran). On le pointe sur une largeur fixe pour aligner le menu
        // à gauche du bouton.
        $rect = GtkRect::create(
            0,
            0,
            320,
            max(1, $trigger->height()),
        );
        $popover->setPointingTo(FFI::addr($rect));
        $popover->popup();
    }
}
