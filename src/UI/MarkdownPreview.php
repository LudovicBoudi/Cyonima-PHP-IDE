<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw\Box as RawBox;
use Cyonima\Gtk\Raw\Orientation;
use Cyonima\Runtime\Markdown;

/**
 * Aperçu Markdown natif : rendu Pango dans un label sélectionnable.
 */
final class MarkdownPreview extends Widget
{
    private Label $label;
    private string $markup = '';

    public function __construct()
    {
        parent::__construct(RawBox::new(Orientation::VERTICAL, 0));

        $this->cssClass('markdown-preview');

        $this->label = (new Label(''))
            ->cssClass('markdown-body')
            ->xalign(0.0);

        $this->label->raw()->setWrap(true);
        $this->label->raw()->setSelectable(true);

        $this->raw()->append($this->label->raw());
    }

    public function raw(): RawBox
    {
        /** @var RawBox $raw */
        $raw = $this->raw;
        return $raw;
    }

    public function render(string $markdown): static
    {
        $this->markup = Markdown::toPango($markdown);
        $this->label->markup($this->markup !== '' ? $this->markup : '<i>Document vide</i>');

        return $this;
    }

    public function markup(): string
    {
        return $this->markup;
    }
}
