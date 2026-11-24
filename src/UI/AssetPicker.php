<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw\Box as RawBox;
use Cyonima\Gtk\Raw\Orientation;
use Cyonima\Gtk\Raw\Popover as RawPopover;
use Cyonima\Runtime\GtkRect;
use Cyonima\Runtime\ProjectFactory;
use FFI;

/**
 * Sélecteur d'assets ancré à un bouton.
 *
 * Il liste les fichiers de `assets/` et propose éventuellement d'ouvrir le
 * sélecteur natif du système. Aucun asset n'est copié : le chemin relatif est
 * simplement renvoyé au concepteur.
 */
final class AssetPicker
{
    private RawPopover $popover;
    private Box $content;
    private Box $list;
    private string $root;

    /** @var null|\Closure(): void */
    private ?\Closure $onBrowse;

    /** @var null|\Closure(string): void */
    private ?\Closure $onSelect = null;

    /** @var list<Widget> */
    private array $rows = [];

    private ?Button $browseButton = null;
    private bool $parented = false;

    public function __construct(string $root, ?callable $onBrowse = null)
    {
        $this->root = rtrim($root, '/');
        $this->onBrowse = $onBrowse === null ? null : $onBrowse(...);

        $this->popover = RawPopover::new();
        $this->popover->setHasArrow(false);
        $this->popover->setAutohide(true);

        $this->list = (new Box(Orientation::VERTICAL, 2))->cssClass('asset-picker-list');
        $this->content = (new Box(Orientation::VERTICAL, 6))->cssClass('asset-picker');

        $scroll = (new ScrolledWindow($this->list))->expand();
        $scroll->raw()->setSizeRequest(280, 250);

        $this->content->append(
            (new Label('ASSETS DU PROJET'))->cssClass('asset-picker-title')->xalign(0.0),
            $scroll,
        );

        if ($this->onBrowse !== null) {
            $this->browseButton = (new Button('Parcourir le système…'))
                ->cssClass('asset-picker-browse')
                ->expand(true, false)
                ->onClicked(function (): void {
                    $this->popover->popdown();
                    if ($this->onBrowse !== null) {
                        ($this->onBrowse)();
                    }
                });

            $this->content->append($this->browseButton);
        }

        $this->popover->setChild($this->content->raw());
    }

    public function open(Widget $anchor, callable $onSelect): void
    {
        $this->onSelect = $onSelect(...);
        $this->rebuild();

        if ($this->parented) {
            $this->popover->unparent();
        }
        $this->popover->setParent($anchor->raw());
        $this->parented = true;

        $rect = GtkRect::create(0, 0, 320, max(1, $anchor->height()));
        $this->popover->setPointingTo(FFI::addr($rect));
        $this->popover->popup();
    }

    public function close(): void
    {
        $this->popover->popdown();
        if ($this->parented) {
            $this->popover->unparent();
            $this->parented = false;
        }
    }

    public function __destruct()
    {
        if ($this->parented) {
            $this->popover->unparent();
        }
    }

    private function rebuild(): void
    {
        foreach ($this->rows as $row) {
            $this->list->remove($row);
        }
        $this->rows = [];

        $assets = ProjectFactory::listAssets($this->root);

        if ($assets === []) {
            $empty = (new Label('Aucun fichier dans assets/.'))->cssClass('asset-picker-empty')->xalign(0.0);
            $this->list->append($empty);
            $this->rows[] = $empty;
            return;
        }

        foreach ($assets as $asset) {
            $content = (new Box(Orientation::VERTICAL, 1))
                ->append(
                    (new Label($asset['name']))->cssClass('asset-picker-name')->xalign(0.0),
                    (new Label($asset['kind']))->cssClass('asset-picker-kind')->xalign(0.0),
                )
                ->expand(true, false);

            $button = (new Button())
                ->cssClass('asset-picker-row')
                ->child($content)
                ->expand(true, false)
                ->onClicked(function () use ($asset): void {
                    $this->popover->popdown();
                    if ($this->onSelect !== null) {
                        ($this->onSelect)($asset['path']);
                    }
                });

            $this->list->append($button);
            $this->rows[] = $button;
        }
    }
}
