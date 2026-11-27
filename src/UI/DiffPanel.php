<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw\Box as RawBox;
use Cyonima\Gtk\Raw\Orientation;

/**
 * Vue de diff en lecture seule, colorée par GtkSourceView (langage diff).
 */
final class DiffPanel extends Widget
{
    private Label $titleLabel;
    private CodeEditor $editor;

    public function __construct()
    {
        parent::__construct(RawBox::new(Orientation::VERTICAL, 0));

        $this->titleLabel = (new Label('Aucun diff sélectionné'))->cssClass('diff-title');

        $this->editor = new CodeEditor('diff');
        $this->editor->editable(false);

        $this->raw()->append($this->titleLabel->raw());
        $this->raw()->append(
            (new ScrolledWindow($this->editor))->expand()->raw(),
        );
    }

    public function raw(): RawBox
    {
        /** @var RawBox $raw */
        $raw = $this->raw;
        return $raw;
    }

    public function show(string $path, string $diff): static
    {
        $this->titleLabel->text('Diff — ' . $path);
        $this->editor->setText(trim($diff) !== '' ? $diff : 'Aucune différence');
        $this->editor->moveCursorToLine(0);

        return $this;
    }

    public function clear(): static
    {
        $this->titleLabel->text('Aucun diff sélectionné');
        $this->editor->setText('');

        return $this;
    }

    public function title(): string
    {
        return $this->titleLabel->getText();
    }

    public function text(): string
    {
        return $this->editor->text();
    }

    public function editor(): CodeEditor
    {
        return $this->editor;
    }
}
