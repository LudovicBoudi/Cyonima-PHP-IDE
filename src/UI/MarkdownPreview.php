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

    /** @var list<int> map[ligne source] = ligne affichée */
    private array $lineMap = [];

    private int $renderedLines = 0;

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
        $rendered = Markdown::toPangoMapped($markdown);
        $this->markup = $rendered['pango'];
        $this->lineMap = $rendered['map'];
        $this->renderedLines = $this->markup === '' ? 0 : count(explode("\n", $this->markup));
        $this->label->markup($this->markup !== '' ? $this->markup : '<i>Document vide</i>');

        return $this;
    }

    public function markup(): string
    {
        return $this->markup;
    }

    public function renderedLineCount(): int
    {
        return $this->renderedLines;
    }

    /** @return list<int> */
    public function lineMap(): array
    {
        return $this->lineMap;
    }

    public function previewLineForSourceLine(int $sourceLine): int
    {
        if ($this->lineMap === []) {
            return 0;
        }

        $sourceLine = max(0, min($sourceLine, count($this->lineMap) - 1));

        return max(0, min(
            $this->lineMap[$sourceLine],
            max(0, $this->renderedLines - 1),
        ));
    }

    public function sourceLineForPreviewLine(int $previewLine): int
    {
        if ($this->lineMap === []) {
            return 0;
        }

        $sourceLine = 0;

        foreach ($this->lineMap as $source => $rendered) {
            if ($rendered <= $previewLine) {
                $sourceLine = $source;
            } else {
                break;
            }
        }

        return $sourceLine;
    }
}
