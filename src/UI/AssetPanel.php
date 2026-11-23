<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw\Box as RawBox;
use Cyonima\Gtk\Raw\Orientation;
use Cyonima\Runtime\ProjectFactory;

/**
 * Panneau des assets d'un projet.
 *
 * Liste les fichiers de `assets/`, permet d'en importer un par son chemin et
 * ouvre le fichier choisi dans l'éditeur.
 */
final class AssetPanel extends Widget
{
    private string $root;
    private Box $list;
    private Entry $pathEntry;
    private Button $addButton;
    private Label $message;
    private Label $empty;

    /** @var list<Widget> */
    private array $rows = [];

    /** @var \Closure(string): void */
    private \Closure $onOpen;

    /** @var null|\Closure(string): void */
    private ?\Closure $onAdded;

    public function __construct(string $root, callable $onOpen, ?callable $onAdded = null)
    {
        $this->root = realpath($root) ?: $root;
        $this->onOpen = $onOpen(...);
        $this->onAdded = $onAdded === null ? null : $onAdded(...);

        parent::__construct(RawBox::new(Orientation::VERTICAL, 6));
        $this->raw()->addCssClass('assets-panel');

        $this->pathEntry = (new Entry('/chemin/vers/asset'))->cssClass('asset-entry')->expand(true, false);
        $this->pathEntry->onActivate(fn () => $this->addAsset());

        $this->addButton = (new Button('Ajouter'))
            ->cssClass('asset-add')
            ->onClicked(fn () => $this->addAsset());

        $this->message = (new Label(''))->cssClass('asset-message')->xalign(0.0)->wrap(true)->visible(false);
        $this->empty = (new Label('Aucun asset dans assets/.'))->cssClass('asset-empty')->xalign(0.0);

        $this->list = (new Box(Orientation::VERTICAL, 2))->append($this->empty);

        $this->raw()->append((new Box(Orientation::HORIZONTAL, 4))->append($this->pathEntry, $this->addButton)->raw());
        $this->raw()->append($this->message->raw());
        $this->raw()->append($this->list->raw());

        $this->refresh();
    }

    public function raw(): RawBox
    {
        /** @var RawBox $raw */
        $raw = $this->raw;
        return $raw;
    }

    public function refresh(): void
    {
        foreach ($this->rows as $row) {
            $this->list->remove($row);
        }
        $this->rows = [];

        $assets = ProjectFactory::listAssets($this->root);
        $this->empty->visible($assets === []);

        foreach ($assets as $asset) {
            $name = (new Label($asset['name']))->cssClass('asset-name')->xalign(0.0);
            $meta = (new Label($asset['kind'] . ' · ' . $this->formatSize($asset['size'])))
                ->cssClass('asset-meta')
                ->xalign(0.0);

            $content = (new Box(Orientation::VERTICAL, 1))
                ->append($name, $meta)
                ->expand(true, false);

            $row = (new Button())
                ->cssClass('asset-row')
                ->child($content)
                ->expand(true, false)
                ->tooltip($asset['absolute'])
                ->onClicked(fn () => ($this->onOpen)($asset['absolute']));

            $this->list->append($row);
            $this->rows[] = $row;
        }
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function addAsset(): bool
    {
        $path = trim($this->pathEntry->text());

        if ($path === '') {
            $this->showMessage('Indiquez le chemin d\'un fichier.', false);
            return false;
        }

        $result = ProjectFactory::importAsset($this->root, $path);
        $this->showMessage($result['message'], $result['ok']);

        if (!$result['ok']) {
            return false;
        }

        $this->pathEntry->setText('');
        $this->refresh();

        if ($this->onAdded !== null) {
            ($this->onAdded)($result['message']);
        }

        return true;
    }

    private function showMessage(string $message, bool $success): void
    {
        $this->message
            ->removeCssClass('error')
            ->cssClass('asset-message')
            ->text($message)
            ->visible(true);

        if (!$success) {
            $this->message->cssClass('error');
        }
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' o';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' Ko';
        }

        return round($bytes / (1024 * 1024), 1) . ' Mo';
    }
}
