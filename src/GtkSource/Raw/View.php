<?php

declare(strict_types=1);

/**
 * Fichier généré depuis GtkSource-5.typelib — ne pas éditer.
 * Régénération : php bin/cyonima-gen generate
 */

namespace Cyonima\GtkSource\Raw;

class View extends \Cyonima\Gtk\Raw\TextView
{
    public const TYPE_NAME = 'GtkSourceView';

    public static function new(): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->gtk_source_view_new();
        return new self(self::adopt($pointer));
    }

    public static function newWithBuffer(?\Cyonima\GtkSource\Raw\Buffer $buffer): self
    {
        Symbols::ensureInitialized();
        $pointer = Symbols::get()->gtk_source_view_new_with_buffer($buffer?->handle());
        return new self(self::adopt($pointer));
    }

    public function setShowLineNumbers(bool $showLineNumbers): void
    {
        Symbols::get()->gtk_source_view_set_show_line_numbers($this->handle, ($showLineNumbers) ? 1 : 0);
    }

    public function setHighlightCurrentLine(bool $highlightCurrentLine): void
    {
        Symbols::get()->gtk_source_view_set_highlight_current_line($this->handle, ($highlightCurrentLine) ? 1 : 0);
    }

    public function setTabWidth(int $tabWidth): void
    {
        Symbols::get()->gtk_source_view_set_tab_width($this->handle, $tabWidth);
    }

    public function setAutoIndent(bool $autoIndent): void
    {
        Symbols::get()->gtk_source_view_set_auto_indent($this->handle, ($autoIndent) ? 1 : 0);
    }

    public function setIndentWidth(int $indentWidth): void
    {
        Symbols::get()->gtk_source_view_set_indent_width($this->handle, $indentWidth);
    }

    public function setInsertSpacesInsteadOfTabs(bool $insertSpaces): void
    {
        Symbols::get()->gtk_source_view_set_insert_spaces_instead_of_tabs($this->handle, ($insertSpaces) ? 1 : 0);
    }

    public function setShowRightMargin(bool $showRightMargin): void
    {
        Symbols::get()->gtk_source_view_set_show_right_margin($this->handle, ($showRightMargin) ? 1 : 0);
    }

    public function setRightMarginPosition(int $rightMarginPosition): void
    {
        Symbols::get()->gtk_source_view_set_right_margin_position($this->handle, $rightMarginPosition);
    }

    public function getShowLineNumbers(): bool
    {
        $result = Symbols::get()->gtk_source_view_get_show_line_numbers($this->handle);
        return ($result) !== 0;
    }
}
