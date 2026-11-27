<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

use FFI\CData;
use WeakReference;

/**
 * Synchronisation du défilement vertical entre deux GtkScrolledWindow.
 *
 * La position relative (fraction de la plage défilable) est reproduite d'un
 * volet vers l'autre. Un verrou évite la boucle de rétroaction entre les deux
 * signaux `value-changed`.
 */
final class ScrollSync
{
    private ?CData $sourceAdjustment = null;
    private ?CData $targetAdjustment = null;
    private ?int $sourceHandler = null;
    private ?int $targetHandler = null;
    private bool $syncing = false;

    /** @var (\Closure(bool, float): float)|null */
    private ?\Closure $mapper = null;

    public function __construct(
        CData $sourceScroller,
        CData $targetScroller,
        ?callable $mapper = null,
    ) {
        if ($mapper !== null) {
            $this->mapper = $mapper(...);
        }

        $this->sourceAdjustment = GtkScroll::vadjustment($sourceScroller);
        $this->targetAdjustment = GtkScroll::vadjustment($targetScroller);

        if ($this->sourceAdjustment === null || $this->targetAdjustment === null) {
            return;
        }

        $self = WeakReference::create($this);
        $source = $this->sourceAdjustment;
        $target = $this->targetAdjustment;

        $this->sourceHandler = GtkScroll::connectValueChanged(
            $source,
            static function () use ($self, $source, $target): void {
                $sync = $self->get();

                if ($sync instanceof self) {
                    $sync->sync($source, $target, true);
                }
            },
        );
        $this->targetHandler = GtkScroll::connectValueChanged(
            $target,
            static function () use ($self, $source, $target): void {
                $sync = $self->get();

                if ($sync instanceof self) {
                    $sync->sync($target, $source, false);
                }
            },
        );
    }

    /** @param null|callable(bool, float): float $mapper */
    public function setMapper(?callable $mapper): static
    {
        $this->mapper = $mapper === null ? null : $mapper(...);
        return $this;
    }

    public function active(): bool
    {
        return $this->sourceAdjustment !== null && $this->targetAdjustment !== null;
    }

    public function syncFromSource(): void
    {
        if ($this->sourceAdjustment !== null && $this->targetAdjustment !== null) {
            $this->sync($this->sourceAdjustment, $this->targetAdjustment, true);
        }
    }

    public function syncFromTarget(): void
    {
        if ($this->sourceAdjustment !== null && $this->targetAdjustment !== null) {
            $this->sync($this->targetAdjustment, $this->sourceAdjustment, false);
        }
    }

    public function detach(): void
    {
        if ($this->sourceAdjustment !== null && $this->sourceHandler !== null) {
            GtkScroll::disconnect($this->sourceAdjustment, $this->sourceHandler);
        }

        if ($this->targetAdjustment !== null && $this->targetHandler !== null) {
            GtkScroll::disconnect($this->targetAdjustment, $this->targetHandler);
        }

        $this->sourceHandler = null;
        $this->targetHandler = null;
        $this->sourceAdjustment = null;
        $this->targetAdjustment = null;
    }

    public function __destruct()
    {
        $this->detach();
    }

    private function sync(CData $from, CData $to, bool $fromSource): void
    {
        if ($this->syncing) {
            return;
        }

        $this->syncing = true;
        $fraction = GtkScroll::fraction($from);

        if ($this->mapper !== null) {
            $fraction = ($this->mapper)($fromSource, $fraction);
        }

        GtkScroll::setFraction($to, $fraction);
        $this->syncing = false;
    }
}
