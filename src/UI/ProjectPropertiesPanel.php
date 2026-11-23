<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw\Box as RawBox;
use Cyonima\Gtk\Raw\Orientation;
use Cyonima\Runtime\ProjectFactory;

/**
 * Propriétés du projet ouvert : métadonnées et licence.
 *
 * Le changement de licence réécrit `LICENSE` et met à jour le manifeste sans
 * toucher au code source ni au README.
 */
final class ProjectPropertiesPanel extends Widget
{
    private string $root;

    /** @var null|\Closure(string): void */
    private ?\Closure $onLicenseChanged;

    private Label $nameValue;
    private Label $pathValue;
    private Label $templateValue;
    private Label $appIdValue;
    private Label $versionValue;
    private Label $assetsValue;
    private Label $currentLicenseValue;
    private Label $message;
    private Box $choices;

    /** @var array<string, Button> */
    private array $choiceButtons = [];

    private Button $applyButton;
    private string $selectedLicense = 'MIT';

    public function __construct(string $root, ?callable $onLicenseChanged = null)
    {
        $this->root = realpath($root) ?: $root;
        $this->onLicenseChanged = $onLicenseChanged === null ? null : $onLicenseChanged(...);

        parent::__construct(RawBox::new(Orientation::VERTICAL, 0));
        $this->raw()->addCssClass('project-panel');

        $this->nameValue = $this->valueLabel();
        $this->pathValue = $this->valueLabel();
        $this->templateValue = $this->valueLabel();
        $this->appIdValue = $this->valueLabel();
        $this->versionValue = $this->valueLabel();
        $this->assetsValue = $this->valueLabel();
        $this->currentLicenseValue = $this->valueLabel();

        $info = (new Box(Orientation::VERTICAL, 0))->append(
            $this->row('Nom', $this->nameValue),
            $this->row('Chemin', $this->pathValue),
            $this->row('Modèle', $this->templateValue),
            $this->row('Identifiant', $this->appIdValue),
            $this->row('Version', $this->versionValue),
            $this->row('Assets', $this->assetsValue),
            $this->row('Licence actuelle', $this->currentLicenseValue),
        );

        $this->choices = new Box(Orientation::VERTICAL, 4);
        foreach (ProjectFactory::licenses() as $spdx => $name) {
            $label = $spdx === 'Proprietary' ? 'Propriétaire' : $spdx . ' — ' . $name;
            $button = (new Button($label))
                ->cssClass('project-choice')
                ->expand(true, false)
                ->onClicked(fn () => $this->selectLicense($spdx));

            $this->choiceButtons[$spdx] = $button;
            $this->choices->append($button);
        }

        $this->applyButton = (new Button('Mettre à jour LICENSE'))
            ->cssClass('project-apply')
            ->onClicked(fn () => $this->applyLicense());

        $this->message = (new Label(''))
            ->cssClass('project-message')
            ->xalign(0.0)
            ->wrap(true)
            ->visible(false);

        $section = (new Box(Orientation::VERTICAL, 8))->append(
            (new Label('LICENCE DU PROJET'))->cssClass('project-section')->xalign(0.0),
            $this->choices,
            $this->applyButton,
            $this->message,
            (new Label('Met à jour LICENSE et .cyonima/project.json. Le README n\'est pas modifié.'))
                ->cssClass('project-note')
                ->xalign(0.0)
                ->wrap(true),
        );

        $this->raw()->append(
            (new Label('PROJET'))->cssClass('project-section')->xalign(0.0)->raw(),
        );
        $this->raw()->append($info->raw());
        $this->raw()->append($section->raw());

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
        $manifest = ProjectFactory::manifest($this->root);

        if ($manifest === null) {
            $this->nameValue->text(basename($this->root));
            $this->pathValue->text($this->root);
            $this->templateValue->text('Dossier simple');
            $this->appIdValue->text('—');
            $this->versionValue->text('—');
            $this->assetsValue->text((string) count(ProjectFactory::listAssets($this->root)));
            $this->currentLicenseValue->text('—');
            $this->applyButton->sensitive(false);
            return;
        }

        $this->nameValue->text((string) ($manifest['name'] ?? basename($this->root)));
        $this->pathValue->text($this->root);
        $this->templateValue->text((string) ($manifest['templateName'] ?? $manifest['template'] ?? '—'));
        $this->appIdValue->text((string) ($manifest['appId'] ?? '—'));
        $this->versionValue->text((string) ($manifest['version'] ?? '—'));

        $assets = count(ProjectFactory::listAssets($this->root));
        $this->assetsValue->text($assets . ' fichier' . ($assets > 1 ? 's' : ''));

        $licenses = ProjectFactory::licenses();
        $spdx = is_string($manifest['license']['spdx'] ?? null) ? $manifest['license']['spdx'] : '';
        $this->currentLicenseValue->text(
            isset($licenses[$spdx]) ? $spdx . ' — ' . $licenses[$spdx] : ($spdx !== '' ? $spdx : '—'),
        );

        $this->selectLicense(isset($licenses[$spdx]) ? $spdx : array_key_first($licenses), false);
        $this->applyButton->sensitive(true);
    }

    public function selectedLicense(): string
    {
        return $this->selectedLicense;
    }

    public function projectName(): string
    {
        $manifest = ProjectFactory::manifest($this->root);

        return is_string($manifest['name'] ?? null) ? $manifest['name'] : basename($this->root);
    }

    private function selectLicense(string $spdx, bool $clearMessage = true): void
    {
        if (!isset(ProjectFactory::licenses()[$spdx])) {
            return;
        }

        $this->selectedLicense = $spdx;

        foreach ($this->choiceButtons as $key => $button) {
            if ($key === $spdx) {
                $button->cssClass('selected');
            } else {
                $button->removeCssClass('selected');
            }
        }

        if ($clearMessage) {
            $this->message->visible(false);
        }
    }

    private function applyLicense(): void
    {
        $result = ProjectFactory::updateLicense($this->root, $this->selectedLicense);
        $this->showMessage($result['message'], $result['ok']);

        if (!$result['ok']) {
            return;
        }

        $this->refresh();

        if ($this->onLicenseChanged !== null) {
            ($this->onLicenseChanged)($this->selectedLicense);
        }
    }

    private function showMessage(string $message, bool $success): void
    {
        $this->message
            ->removeCssClass('error')
            ->cssClass('project-message')
            ->text($message)
            ->visible(true);

        if (!$success) {
            $this->message->cssClass('error');
        }
    }

    private function valueLabel(): Label
    {
        return (new Label('—'))->cssClass('project-value')->xalign(0.0)->wrap(true)->selectable();
    }

    private function row(string $key, Label $value): Box
    {
        return (new Box(Orientation::VERTICAL, 1))
            ->cssClass('project-row')
            ->append(
                (new Label($key))->cssClass('project-key')->xalign(0.0),
                $value,
            );
    }
}
