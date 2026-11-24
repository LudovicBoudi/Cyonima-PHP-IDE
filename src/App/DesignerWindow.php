<?php

declare(strict_types=1);

namespace Cyonima\App;

use Cyonima\Gtk\Raw\GestureClick as RawGestureClick;
use Cyonima\Gtk\Raw\GestureDrag as RawGestureDrag;
use Cyonima\Gtk\Raw\Orientation;
use Cyonima\Runtime\AdwApplication;
use Cyonima\Runtime\FilePicker;
use Cyonima\Runtime\FormDocument;
use Cyonima\Runtime\ProjectFactory;
use Cyonima\UI\AdwWindow;
use Cyonima\UI\AssetPicker;
use Cyonima\UI\Box;
use Cyonima\UI\Button;
use Cyonima\UI\Entry;
use Cyonima\UI\Fixed;
use Cyonima\UI\IconButton;
use Cyonima\UI\Image;
use Cyonima\UI\Label;
use Cyonima\UI\ScrolledWindow;
use Cyonima\UI\Separator;
use Cyonima\UI\SwitchRow;
use Cyonima\UI\Theme;
use Cyonima\UI\Video;
use Cyonima\UI\Widget;
use FFI;

/**
 * Concepteur visuel de formulaires.
 *
 * Palette à gauche, canevas au centre, propriétés à droite. Les contrôles se
 * déposent par glisser-déposer ou par clic. Le formulaire est sérialisé en
 * `.cyonima/forms/*.form.json` et généré en classe PHP `App\Forms\...`.
 */
final class DesignerWindow
{
    private const POINT_CDEF = <<<'C'
        typedef struct { float x, y; } CyonimaPoint;
        int gtk_widget_compute_point(void *widget, void *target, const CyonimaPoint *point, CyonimaPoint *out);
        C;

    private AdwWindow $window;
    private FormDocument $form;
    private Fixed $canvas;
    private string $root;

    /** @var null|\Closure(string): void */
    private ?\Closure $onSaved = null;

    /** @var null|\Closure(): void */
    private ?\Closure $onClosed = null;

    /** @var list<Widget> */
    private array $keepAlive = [];

    /** @var list<Widget> */
    private array $formRows = [];

    private Box $formsBox;
    private Button $newFormButton;
    private Label $canvasSizeLabel;
    private Label $textFieldLabel;
    private AssetPicker $assetPicker;
    private string $assetTarget = 'text';

    /** @var list<object> */
    private array $controllers = [];

    /**
     * @var array<string, array{
     *     wrapper: Box,
     *     child: Widget,
     *     drag: array{0: int, 1: int}
     * }>
     */
    private array $items = [];

    private ?string $selectedId = null;
    private int $cascade = 24;

    private Entry $formNameEntry;
    private Entry $nameEntry;
    private Box $eventsBox;
    private Entry $textEntry;
    private Entry $iconEntry;
    private IconButton $textBrowseButton;
    private IconButton $iconBrowseButton;
    private Entry $xEntry;
    private Entry $yEntry;
    private Entry $wEntry;
    private Entry $hEntry;
    private Label $selectionValue;
    private Label $typeValue;
    private Label $message;
    private Button $applyButton;
    private Button $deleteButton;
    private Button $saveButton;

    /** @var array<string, Button> */
    private array $eventButtons = [];

    public function __construct(
        AdwApplication $app,
        string $root,
        ?callable $onSaved = null,
        ?callable $onClosed = null,
    ) {
        $this->root = rtrim(realpath($root) ?: $root, '/');
        $this->onSaved = $onSaved === null ? null : $onSaved(...);
        $this->onClosed = $onClosed === null ? null : $onClosed(...);

        $forms = FormDocument::listForms($this->root);
        $initialName = 'main-form';
        if (FormDocument::load($this->root, $initialName) === null && $forms !== []) {
            $initialName = $forms[0]['name'];
        }
        $this->form = FormDocument::load($this->root, $initialName) ?? new FormDocument($initialName);
        $projectName = ProjectFactory::projectName($this->root);
        $this->window = $app->window('Concepteur d\'interface', $projectName);
        $this->window->raw()->setDefaultSize(1240, 800);

        Theme::apply($this->window);
        $this->assetPicker = new AssetPicker($this->root, function (): void {
            $this->browseSystemAsset($this->assetTarget);
        });

        $this->window->onCloseRequest(function (): void {
            $this->assetPicker->close();

            if ($this->onClosed !== null) {
                ($this->onClosed)();
            }
        });

        $this->build();
    }

    public function present(): void
    {
        $this->window->present();
    }

    public function close(): void
    {
        $this->window->raw()->close();
    }

    private function build(): void
    {
        $properties = (new ScrolledWindow($this->propertiesPanel()))->expand(false, true);
        $properties->raw()->setSizeRequest(300, -1);

        $root = (new Box(Orientation::HORIZONTAL, 0))->expand();
        $root->append($this->palette(), $this->canvasArea(), $properties);

        $this->window->content($root);
        $this->renderForm();
        $this->refreshFormList();
    }

    // --- Palette -----------------------------------------------------------

    private function palette(): Box
    {
        $panel = (new Box(Orientation::VERTICAL, 0))
            ->cssClass('designer-palette')
            ->expand(false, true);
        $panel->raw()->setSizeRequest(220, -1);

        $this->formsBox = new Box(Orientation::VERTICAL, 2);
        $this->newFormButton = (new Button('+ Nouveau formulaire'))
            ->cssClass('designer-form-new')
            ->onClicked(fn () => $this->newForm());

        $formsScroll = new ScrolledWindow($this->formsBox);
        $formsScroll->raw()->setSizeRequest(198, 110);

        $panel->append(
            (new Label('FORMULAIRES'))->cssClass('designer-section')->xalign(0.0),
            $formsScroll,
            $this->newFormButton,
            (new Label('CONTRÔLES'))->cssClass('designer-section')->xalign(0.0),
            (new Label('Cliquez ou glissez sur le canevas.'))->cssClass('designer-hint')->xalign(0.0)->wrap(true),
        );

        foreach (FormDocument::types() as $type => $definition) {
            $item = (new Box(Orientation::VERTICAL, 2))
                ->cssClass('designer-palette-item')
                ->append(
                    (new Label($definition['name']))->cssClass('designer-palette-name')->xalign(0.0),
                    (new Label('Glisser-déposer'))->cssClass('designer-palette-hint')->xalign(0.0),
                )
                ->expand(true, false);

            $this->attachPaletteGestures($item, $type);
            $panel->append($item);
            $this->keepAlive[] = $item;
        }

        $panel->append((new Box(Orientation::VERTICAL, 0))->expand(false, true));

        return $panel;
    }

    private function attachPaletteGestures(Box $item, string $type): void
    {
        $drag = RawGestureDrag::new();
        $start = [0.0, 0.0];

        $drag->connectSignal(
            RawGestureDrag::SIGNAL_DRAG_BEGIN,
            function ($gesture, $x, $y) use (&$start): void {
                $start = [(float) $x, (float) $y];
                $this->message->text('Déposez le contrôle sur le canevas…')->visible(true);
            },
            ['double', 'double'],
            false,
        );

        $drag->connectSignal(
            RawGestureDrag::SIGNAL_DRAG_END,
            function ($gesture, $offsetX, $offsetY) use (&$start, $type, $item): void {
                // Un simple clic est interprété comme « ajouter au canevas » ;
                // au-delà de 3 px, c'est un vrai glisser-déposer.
                if (abs($offsetX) < 3.0 && abs($offsetY) < 3.0) {
                    $this->addWidget($type, $this->cascade, $this->cascade + 12);
                    return;
                }

                $point = self::computePoint(
                    $item,
                    $this->canvas,
                    $start[0] + (float) $offsetX,
                    $start[1] + (float) $offsetY,
                );

                if ($point === null
                    || $point[0] < 0
                    || $point[1] < 0
                    || $point[0] > $this->form->width()
                    || $point[1] > $this->form->height()
                ) {
                    $this->message->text('Déposez le contrôle dans le canevas.')->visible(true);
                    return;
                }

                $this->message->visible(false);
                $this->addWidget($type, (int) round($point[0]), (int) round($point[1]));
            },
            ['double', 'double'],
            false,
        );

        $item->raw()->addController($drag);
        $this->controllers[] = $drag;
    }

    private function refreshFormList(): void
    {
        foreach ($this->formRows as $row) {
            $this->formsBox->remove($row);
        }
        $this->formRows = [];

        $forms = FormDocument::listForms($this->root);
        $names = array_column($forms, 'name');

        if (!in_array($this->form->name(), $names, true)) {
            $forms[] = [
                'name' => $this->form->name(),
                'slug' => FormDocument::slug($this->form->name()),
                'className' => $this->form->className(),
                'path' => '',
            ];
            usort($forms, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        }

        $currentSlug = FormDocument::slug($this->form->name());

        foreach ($forms as $form) {
            $button = (new Button($form['name']))
                ->cssClass('designer-form-item')
                ->expand(true, false)
                ->onClicked(fn () => $this->switchForm($form['name']));

            if (FormDocument::slug($form['name']) === $currentSlug) {
                $button->cssClass('selected');
            }

            $this->formsBox->append($button);
            $this->formRows[] = $button;
        }
    }

    private function switchForm(string $name): void
    {
        if (FormDocument::slug($name) === FormDocument::slug($this->form->name())) {
            return;
        }

        $this->persistCurrent();
        $loaded = FormDocument::load($this->root, $name);
        if ($loaded === null) {
            return;
        }

        $this->form = $loaded;
        $this->formNameEntry->setText($loaded->name());
        $this->resetCanvas();
        $this->canvas->size($loaded->width(), $loaded->height());
        $this->canvasSizeLabel->text($loaded->width() . ' × ' . $loaded->height());
        $this->renderForm();
        $this->refreshFormList();
        $this->showMessage('Formulaire ouvert : ' . $loaded->name(), true);
    }

    private function newForm(): void
    {
        $this->persistCurrent();

        $name = FormDocument::uniqueFormName($this->root, 'form');
        $this->form = new FormDocument($name);
        $this->formNameEntry->setText($name);
        $this->resetCanvas();
        $this->canvas->size($this->form->width(), $this->form->height());
        $this->canvasSizeLabel->text($this->form->width() . ' × ' . $this->form->height());
        $this->refreshFormList();
        $this->showMessage('Nouveau formulaire : ' . $name, true);
    }

    private function persistCurrent(): void
    {
        $path = $this->root . '/.cyonima/forms/' . FormDocument::slug($this->form->name()) . '.form.json';
        if (!is_file($path) && $this->form->widgets() === []) {
            return;
        }

        $result = $this->form->save($this->root);
        if (!$result['ok']) {
            $this->showMessage($result['message'], false);
        }
    }

    private function resetCanvas(): void
    {
        foreach ($this->items as $item) {
            $this->canvas->remove($item['wrapper']);
        }
        $this->items = [];
        $this->selectedId = null;

        foreach ($this->eventButtons as $button) {
            $this->eventsBox->remove($button);
        }
        $this->eventButtons = [];

        $this->selectionValue->text('Aucun élément');
        $this->typeValue->text('—');
        $this->nameEntry->setText('');
        $this->textEntry->setText('');
        $this->iconEntry->setText('');
        $this->iconEntry->sensitive(false);
        $this->iconBrowseButton->sensitive(false);
        $this->textBrowseButton->sensitive(false);
        $this->xEntry->setText('');
        $this->yEntry->setText('');
        $this->wEntry->setText('');
        $this->hEntry->setText('');
        $this->setPropertiesEnabled(false);
    }

    // --- Canevas -----------------------------------------------------------

    private function canvasArea(): Box
    {
        $this->canvas = (new Fixed($this->form->width(), $this->form->height()))
            ->cssClass('designer-canvas')
            ->align(1);

        $holder = (new Box(Orientation::VERTICAL, 0))
            ->cssClass('designer-canvas-wrap')
            ->margin(24)
            ->append($this->canvas)
            ->expand();

        $scroll = (new ScrolledWindow($holder))->expand();

        $this->canvasSizeLabel = (new Label($this->form->width() . ' × ' . $this->form->height()))
            ->cssClass('designer-hint');

        $header = (new Box(Orientation::HORIZONTAL, 8))
            ->cssClass('designer-canvas-header')
            ->append(
                (new Label('CANEVAS'))->cssClass('designer-section')->expand(true, false),
                $this->canvasSizeLabel,
            );

        return (new Box(Orientation::VERTICAL, 0))
            ->expand()
            ->append($header, $scroll);
    }

    private function renderForm(): void
    {
        foreach ($this->form->widgets() as $widget) {
            $this->attachWidget($widget, false);
        }
    }

    /** @param array{id: string, type: string, x: int, y: int, width: int, height: int, text: string} $item */
    private function attachWidget(array $item, bool $select = true): void
    {
        $child = $this->createChild($item);
        $wrapper = (new Box(Orientation::VERTICAL, 0))
            ->cssClass('designer-item')
            ->cssClass('designer-item-' . $item['type'])
            ->append($child);

        $wrapper->raw()->setSizeRequest($item['width'], $item['height']);
        $child->canTarget(false);

        $this->canvas->put($wrapper, $item['x'], $item['y']);

        $id = $item['id'];
        $this->items[$id] = [
            'wrapper' => $wrapper,
            'child' => $child,
            'drag' => [$item['x'], $item['y']],
        ];

        $this->attachItemGestures($id, $wrapper);
        $this->keepAlive[] = $wrapper;

        if ($select) {
            $this->select($id);
        }
    }

    private function attachItemGestures(string $id, Box $wrapper): void
    {
        $click = RawGestureClick::new();
        $click->connectSignal(
            RawGestureClick::SIGNAL_PRESSED,
            fn () => $this->select($id),
            ['int', 'double', 'double'],
            false,
        );
        $wrapper->raw()->addController($click);
        $this->controllers[] = $click;

        $drag = RawGestureDrag::new();
        $drag->connectSignal(
            RawGestureDrag::SIGNAL_DRAG_BEGIN,
            function () use ($id): void {
                $item = $this->form->get($id);
                if ($item === null) {
                    return;
                }

                $this->items[$id]['drag'] = [$item['x'], $item['y']];
                $this->select($id);
            },
            ['double', 'double'],
            false,
        );

        $drag->connectSignal(
            RawGestureDrag::SIGNAL_DRAG_UPDATE,
            function ($gesture, $offsetX, $offsetY) use ($id): void {
                if (!isset($this->items[$id])) {
                    return;
                }

                [$startX, $startY] = $this->items[$id]['drag'];
                $x = max(0, min($this->form->width() - 20, $startX + (int) round($offsetX)));
                $y = max(0, min($this->form->height() - 20, $startY + (int) round($offsetY)));

                $this->canvas->move($this->items[$id]['wrapper'], $x, $y);
            },
            ['double', 'double'],
            false,
        );

        $drag->connectSignal(
            RawGestureDrag::SIGNAL_DRAG_END,
            function ($gesture, $offsetX, $offsetY) use ($id): void {
                if (!isset($this->items[$id])) {
                    return;
                }

                [$startX, $startY] = $this->items[$id]['drag'];
                $x = max(0, min($this->form->width() - 20, $startX + (int) round($offsetX)));
                $y = max(0, min($this->form->height() - 20, $startY + (int) round($offsetY)));

                $item = $this->form->update($id, ['x' => $x, 'y' => $y]);
                if ($item !== null) {
                    $this->canvas->move($this->items[$id]['wrapper'], $item['x'], $item['y']);
                    $this->items[$id]['drag'] = [$item['x'], $item['y']];

                    if ($this->selectedId === $id) {
                        $this->fillProperties($item);
                    }
                }
            },
            ['double', 'double'],
            false,
        );

        $wrapper->raw()->addController($drag);
        $this->controllers[] = $drag;
    }

    private function createChild(array $item): Widget
    {
        $text = $item['text'];

        return match ($item['type']) {
            'button' => $this->makeButton($item),
            'entry' => (new Entry($text))->cssClass('form-entry'),
            'switch' => (new SwitchRow($text, '', false))->cssClass('form-switch'),
            'image' => (new Image($this->resolveAsset($text)))->cssClass('form-image'),
            'video' => (new Video($this->resolveAsset($text)))->cssClass('form-video'),
            'separator' => (new Separator())->cssClass('form-separator'),
            default => (new Label($text))->cssClass('form-label'),
        };
    }

    private function chooseAsset(string $target): void
    {
        if ($this->selectedId === null) {
            return;
        }

        $item = $this->form->get($this->selectedId);
        if ($item === null) {
            return;
        }

        if ($target === 'icon' && $item['type'] !== 'button') {
            return;
        }
        if ($target === 'text' && !in_array($item['type'], ['image', 'video'], true)) {
            return;
        }

        $this->assetTarget = $target;
        $anchor = $target === 'icon' ? $this->iconBrowseButton : $this->textBrowseButton;

        $this->assetPicker->open($anchor, function (string $path) use ($target): void {
            $this->applyAssetPath($target, $path);
        });
    }

    private function browseSystemAsset(string $target): void
    {
        FilePicker::choose(
            $this->window->raw()->handle(),
            $target === 'icon' ? 'Choisir une image pour le bouton' : 'Choisir une image ou une vidéo',
            function (string $path) use ($target): void {
                $this->applyAssetPath($target, $path);
            },
        );
    }

    private function applyAssetPath(string $target, string $path): void
    {
        $path = $this->relativeAsset($path);

        if ($target === 'icon') {
            $this->iconEntry->setText($path);
        } else {
            $this->textEntry->setText($path);
        }

        $this->applySelection();
        $this->message->text('Fichier sélectionné : ' . $path)->visible(true);
    }

    private function relativeAsset(string $path): string
    {
        $real = realpath($path);
        $root = realpath($this->root);

        if ($real !== false && $root !== false && str_starts_with($real, $root . '/')) {
            return substr($real, strlen($root) + 1);
        }

        return $path;
    }

    private function makeButton(array $item): Button
    {
        $button = (new Button())->cssClass('form-button');
        $button->child($this->buttonContent($item['text'], $item['icon'] ?? ''));

        return $button;
    }

    private function buttonContent(string $text, string $icon): Box
    {
        $content = (new Box(Orientation::HORIZONTAL, 6));

        if ($icon !== '') {
            $content->append((new Image($this->resolveAsset($icon)))->sizeRequest(20, 20));
        }
        if ($text !== '') {
            $content->append((new Label($text)));
        }

        return $content;
    }

    private function resolveAsset(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '';
        }

        if (str_starts_with($path, '~')) {
            $home = getenv('HOME');
            return ($home === false ? '' : rtrim($home, '/')) . substr($path, 1);
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $this->root . '/' . ltrim($path, '/');
    }

    private function updateChild(Widget $child, array $item): void
    {
        $text = $item['text'];

        if ($child instanceof Button) {
            $child->child($this->buttonContent($item['text'], $item['icon'] ?? ''));
        } elseif ($child instanceof Entry) {
            $child->placeholder($text);
        } elseif ($child instanceof SwitchRow) {
            $child->title($text);
        } elseif ($child instanceof Image) {
            $child->fromFile($this->resolveAsset($text));
        } elseif ($child instanceof Video) {
            $child->filename($this->resolveAsset($text));
        } elseif ($child instanceof Label) {
            $child->text($text);
        }
    }

    // --- Propriétés --------------------------------------------------------

    private function propertiesPanel(): Box
    {
        $panel = (new Box(Orientation::VERTICAL, 0))
            ->cssClass('designer-properties')
            ->expand(false, true);
        $panel->raw()->setSizeRequest(300, -1);

        $this->formNameEntry = (new Entry('main-form', $this->form->name()))
            ->cssClass('designer-entry')
            ->expand(true, false);

        $this->saveButton = (new Button('Enregistrer et générer'))
            ->cssClass('designer-save')
            ->onClicked(fn () => $this->saveForm());

        $this->message = (new Label(''))->cssClass('designer-message')->xalign(0.0)->wrap(true)->visible(false);

        $this->selectionValue = (new Label('Aucun élément'))->cssClass('designer-selection')->xalign(0.0);
        $this->typeValue = (new Label('—'))->cssClass('designer-type')->xalign(0.0);
        $this->nameEntry = (new Entry())->cssClass('designer-entry')->expand(true, false);
        $this->eventsBox = new Box(Orientation::VERTICAL, 3);

        $this->textEntry = (new Entry())->cssClass('designer-entry')->expand(true, false);
        $this->iconEntry = (new Entry('assets/icon.svg'))->cssClass('designer-entry')->expand(true, false);
        $this->textBrowseButton = (new IconButton('document-open-symbolic', 'Choisir un fichier'))
            ->cssClass('designer-browse')
            ->onClicked(fn () => $this->chooseAsset('text'));
        $this->iconBrowseButton = (new IconButton('document-open-symbolic', 'Choisir une image'))
            ->cssClass('designer-browse')
            ->onClicked(fn () => $this->chooseAsset('icon'));
        $this->xEntry = (new Entry())->cssClass('designer-entry')->expand(true, false);
        $this->yEntry = (new Entry())->cssClass('designer-entry')->expand(true, false);
        $this->wEntry = (new Entry())->cssClass('designer-entry')->expand(true, false);
        $this->hEntry = (new Entry())->cssClass('designer-entry')->expand(true, false);

        $this->applyButton = (new Button('Appliquer'))
            ->cssClass('designer-apply')
            ->onClicked(fn () => $this->applySelection());

        $this->deleteButton = (new Button('Supprimer'))
            ->cssClass('designer-delete')
            ->onClicked(fn () => $this->deleteSelection());

        $panel->append(
            (new Label('FORMULAIRE'))->cssClass('designer-section')->xalign(0.0),
            $this->field('Nom du formulaire', $this->formNameEntry),
            (new Label('Canevas : ' . $this->form->width() . ' × ' . $this->form->height()))
                ->cssClass('designer-hint')
                ->xalign(0.0),
            $this->saveButton,
            $this->message,
            (new Label('ÉLÉMENT SÉLECTIONNÉ'))->cssClass('designer-section')->xalign(0.0),
            $this->selectionValue,
            $this->typeValue,
            $this->field('Nom du contrôle', $this->nameEntry),
            (new Label('ÉVÉNEMENTS'))->cssClass('designer-section')->xalign(0.0),
            $this->eventsBox,
            $this->textField(),
            $this->assetField('Image du bouton (chemin)', $this->iconEntry, $this->iconBrowseButton),
            (new Box(Orientation::HORIZONTAL, 8))->homogeneous()->append(
                $this->field('X', $this->xEntry),
                $this->field('Y', $this->yEntry),
            ),
            (new Box(Orientation::HORIZONTAL, 8))->homogeneous()->append(
                $this->field('Largeur', $this->wEntry),
                $this->field('Hauteur', $this->hEntry),
            ),
            (new Box(Orientation::HORIZONTAL, 8))->append($this->applyButton, $this->deleteButton),
        );

        $this->setPropertiesEnabled(false);

        return $panel;
    }

    private function textField(): Box
    {
        $this->textFieldLabel = (new Label('Texte / libellé'))
            ->cssClass('designer-field-label')
            ->xalign(0.0);

        return (new Box(Orientation::VERTICAL, 3))
            ->cssClass('designer-field')
            ->append(
                $this->textFieldLabel,
                (new Box(Orientation::HORIZONTAL, 4))
                    ->append($this->textEntry->expand(true, false), $this->textBrowseButton),
            );
    }

    private function assetField(string $label, Entry $entry, IconButton $browse): Box
    {
        return (new Box(Orientation::VERTICAL, 3))
            ->cssClass('designer-field')
            ->append(
                (new Label($label))->cssClass('designer-field-label')->xalign(0.0),
                (new Box(Orientation::HORIZONTAL, 4))
                    ->append($entry->expand(true, false), $browse),
            );
    }

    private function field(string $label, Widget $input): Box
    {
        return $this->fieldWithLabel($label, $input);
    }

    private function fieldWithLabel(string $label, Widget $input): Box
    {
        $labelWidget = new Label($label);

        return (new Box(Orientation::VERTICAL, 3))
            ->cssClass('designer-field')
            ->append($labelWidget->cssClass('designer-field-label')->xalign(0.0), $input);
    }

    private function setPropertiesEnabled(bool $enabled): void
    {
        foreach ([
            $this->nameEntry,
            $this->textEntry,
            $this->iconEntry,
            $this->textBrowseButton,
            $this->iconBrowseButton,
            $this->xEntry,
            $this->yEntry,
            $this->wEntry,
            $this->hEntry,
            $this->applyButton,
            $this->deleteButton,
            $this->eventsBox,
        ] as $widget) {
            $widget->sensitive($enabled);
        }
    }

    private function select(string $id): void
    {
        $item = $this->form->get($id);
        if ($item === null) {
            return;
        }

        if ($this->selectedId !== null && isset($this->items[$this->selectedId])) {
            $this->items[$this->selectedId]['wrapper']->removeCssClass('selected');
        }

        $this->selectedId = $id;
        $this->items[$id]['wrapper']->cssClass('selected');
        $this->fillProperties($item);
        $this->setPropertiesEnabled(true);

        $isButton = $item['type'] === 'button';
        $isMedia = in_array($item['type'], ['image', 'video'], true);
        $this->iconEntry->sensitive($isButton);
        $this->iconBrowseButton->sensitive($isButton);
        $this->textBrowseButton->sensitive($isMedia);
    }

    private function fillProperties(array $item): void
    {
        $types = FormDocument::types();
        $this->selectionValue->text($item['id']);
        $this->typeValue->text($types[$item['type']]['name'] ?? $item['type']);
        $this->nameEntry->setText($item['name']);
        $this->textFieldLabel->text(match ($item['type']) {
            'image', 'video' => 'Chemin du fichier',
            'separator' => 'Texte (ignoré)',
            default => 'Texte / libellé',
        });
        $this->textEntry->setText($item['text']);
        $this->iconEntry->setText($item['icon'] ?? '');
        $this->xEntry->setText((string) $item['x']);
        $this->yEntry->setText((string) $item['y']);
        $this->wEntry->setText((string) $item['width']);
        $this->hEntry->setText((string) $item['height']);
        $this->refreshEventButtons($item);
    }

    private function refreshEventButtons(array $item): void
    {
        foreach ($this->eventButtons as $button) {
            $this->eventsBox->remove($button);
        }
        $this->eventButtons = [];

        foreach (FormDocument::eventsForType($item['type']) as $event => $label) {
            $button = (new Button($label))
                ->cssClass('designer-event')
                ->expand(true, false)
                ->onClicked(fn () => $this->toggleEvent($event));

            if (in_array($event, $item['events'], true)) {
                $button->cssClass('selected');
            }

            $this->eventsBox->append($button);
            $this->eventButtons[$event] = $button;
        }
    }

    private function toggleEvent(string $event): void
    {
        if ($this->selectedId === null) {
            return;
        }

        $item = $this->form->get($this->selectedId);
        if ($item === null) {
            return;
        }

        $events = in_array($event, $item['events'], true)
            ? array_values(array_diff($item['events'], [$event]))
            : [...$item['events'], $event];

        $updated = $this->form->update($this->selectedId, ['events' => $events]);
        if ($updated !== null) {
            $this->refreshEventButtons($updated);
            $this->message
                ->text($event . ' ' . (in_array($event, $updated['events'], true) ? 'activé' : 'désactivé'))
                ->visible(true);
        }
    }

    private function applySelection(): void
    {
        if ($this->selectedId === null || !isset($this->items[$this->selectedId])) {
            return;
        }

        $requestedName = FormDocument::normalizeName($this->nameEntry->text(), $this->form->get($this->selectedId)['type']);

        $item = $this->form->update($this->selectedId, [
            'name' => $requestedName,
            'text' => $this->textEntry->text(),
            'icon' => $this->iconEntry->text(),
            'x' => (int) $this->xEntry->text(),
            'y' => (int) $this->yEntry->text(),
            'width' => (int) $this->wEntry->text(),
            'height' => (int) $this->hEntry->text(),
        ]);

        if ($item === null) {
            return;
        }

        $stored = $this->items[$this->selectedId];
        $this->canvas->move($stored['wrapper'], $item['x'], $item['y']);
        $stored['wrapper']->raw()->setSizeRequest($item['width'], $item['height']);
        $this->updateChild($stored['child'], $item);
        $this->fillProperties($item);

        if ($item['name'] !== $requestedName) {
            $this->showMessage('Nom invalide ou déjà utilisé : ' . $requestedName, false);
            return;
        }

        $this->message->text('Élément mis à jour.')->visible(true);
    }

    private function deleteSelection(): void
    {
        if ($this->selectedId === null || !isset($this->items[$this->selectedId])) {
            return;
        }

        $id = $this->selectedId;
        $this->canvas->remove($this->items[$id]['wrapper']);
        $this->form->remove($id);
        unset($this->items[$id]);

        $this->selectedId = null;
        $this->selectionValue->text('Aucun élément');
        $this->typeValue->text('—');
        $this->nameEntry->setText('');
        $this->textEntry->setText('');
        $this->yEntry->setText('');
        $this->wEntry->setText('');
        $this->hEntry->setText('');
        $this->refreshEventButtons(['type' => 'label', 'events' => []]);
        $this->setPropertiesEnabled(false);
        $this->message->text('Élément supprimé.')->visible(true);
    }

    // --- Ajout / sauvegarde ------------------------------------------------

    private function addWidget(string $type, int $x, int $y): void
    {
        $item = $this->form->add($type, $x, $y);
        $this->attachWidget($item);
        $this->cascade = min(180, $this->cascade + 20);
        $this->message->text('Contrôle ajouté.')->visible(true);
    }

    private function saveForm(): void
    {
        $targetSlug = FormDocument::slug($this->formNameEntry->text());
        $currentSlug = FormDocument::slug($this->form->name());
        $targetPath = $this->root . '/.cyonima/forms/' . $targetSlug . '.form.json';

        if ($targetSlug !== $currentSlug && is_file($targetPath)) {
            $this->showMessage('Un formulaire porte déjà ce nom : ' . $this->formNameEntry->text(), false);
            return;
        }

        $this->form->setName($this->formNameEntry->text());
        $result = $this->form->save($this->root);

        if (!$result['ok']) {
            $this->showMessage($result['message'], false);
            return;
        }

        $this->showMessage($result['message'], true);
        $this->refreshFormList();

        if ($result['phpPath'] !== null && $this->onSaved !== null) {
            ($this->onSaved)($result['phpPath']);
        }
    }

    private function showMessage(string $message, bool $success): void
    {
        $this->message
            ->removeCssClass('error')
            ->cssClass('designer-message')
            ->text($message)
            ->visible(true);

        if (!$success) {
            $this->message->cssClass('error');
        }
    }

    private static ?FFI $pointFfi = null;

    /** @return null|array{0: float, 1: float} */
    private static function computePoint(Widget $source, Widget $target, float $x, float $y): ?array
    {
        if (self::$pointFfi === null) {
            self::$pointFfi = FFI::cdef(self::POINT_CDEF, 'libgtk-4.so.1');
        }

        $in = self::$pointFfi->new('CyonimaPoint');
        $in->x = $x;
        $in->y = $y;
        $out = self::$pointFfi->new('CyonimaPoint');

        if (!self::$pointFfi->gtk_widget_compute_point(
            $source->raw()->handle(),
            $target->raw()->handle(),
            FFI::addr($in),
            FFI::addr($out),
        )) {
            return null;
        }

        return [(float) $out->x, (float) $out->y];
    }
}
