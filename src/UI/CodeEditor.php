<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\GtkSource\Raw\Buffer as RawBuffer;
use Cyonima\GtkSource\Raw\LanguageManager;
use Cyonima\GtkSource\Raw\StyleSchemeManager;
use Cyonima\Gtk\Raw\EventControllerKey as RawEventControllerKey;
use Cyonima\GtkSource\Raw\View as RawView;
use Cyonima\Runtime\GtkText;

/**
 * Éditeur de code natif (GtkSourceView 5).
 *
 * Le buffer est créé explicitement puis injecté dans la vue : les getters
 * typés du C renvoient la classe déclarée (`GtkTextBuffer`), pas le type réel,
 * donc `View::new_with_buffer()` garantit un `GtkSourceBuffer` typé.
 */
final class CodeEditor extends Widget
{
    private RawBuffer $buffer;
    private ?RawEventControllerKey $keyController = null;

    public function __construct(string $language = 'php', string $text = '')
    {
        $this->buffer = RawBuffer::new(null);

        parent::__construct(RawView::newWithBuffer($this->buffer));

        $view = $this->raw();
        $view->setShowLineNumbers(true);
        $view->setHighlightCurrentLine(true);
        $view->setTabWidth(4);
        $view->setIndentWidth(4);
        $view->setAutoIndent(true);
        $view->setInsertSpacesInsteadOfTabs(true);
        $view->setShowRightMargin(true);
        $view->setRightMarginPosition(100);
        $view->setMonospace(true);

        if ($language !== '') {
            $this->language($language);
        }
        if ($text !== '') {
            $this->setText($text);
        }
    }

    public function raw(): RawView
    {
        /** @var RawView $raw */
        $raw = $this->raw;
        return $raw;
    }

    public function buffer(): RawBuffer
    {
        return $this->buffer;
    }

    public function language(string $languageId): static
    {
        $language = LanguageManager::getDefault()?->getLanguage($languageId);
        if ($language !== null) {
            $this->buffer->setLanguage($language);
        }
        return $this;
    }

    public function scheme(string $schemeId): static
    {
        $scheme = StyleSchemeManager::getDefault()?->getScheme($schemeId);
        if ($scheme !== null) {
            $this->buffer->setStyleScheme($scheme);
        }
        return $this;
    }

    /** Remplace tout le contenu (setText avec longueur -1 = NUL-terminé). */
    public function setText(string $text): static
    {
        $this->buffer->setText($text, -1);
        $this->buffer->setModified(false);
        return $this;
    }

    /** Relit tout le contenu depuis le buffer GTK (y compris les frappes). */
    public function text(): string
    {
        return GtkText::fromBuffer($this->buffer->handle());
    }

    /** Appelé à chaque modification du buffer (frappe, setText…). */
    public function onChanged(callable $handler): static
    {
        $this->buffer->connectSignal('changed', fn () => $handler($this), [], true);
        return $this;
    }

    /** @return array{offset: int, line: int, character: int} */
    public function cursor(): array
    {
        return GtkText::cursor($this->buffer->handle());
    }

    /** Rectangle du curseur dans la vue (pour ancrer le popover IntelliSense). */
    public function cursorRect(): array
    {
        return GtkText::cursorRect($this->raw()->handle(), $this->buffer->handle());
    }

    /** Point de code Unicode du caractère avant le curseur. */
    public function charBeforeCursor(): int
    {
        return GtkText::charBeforeCursor($this->buffer->handle());
    }

    /** Mot en cours de frappe avant le curseur. */
    public function wordPrefix(): string
    {
        return GtkText::wordPrefix($this->buffer->handle());
    }

    public function replacePrefix(int $characters, string $replacement): void
    {
        GtkText::replacePrefix($this->buffer->handle(), $characters, $replacement);
    }

    public function moveCursorToEnd(): static
    {
        GtkText::moveCursorToEnd($this->buffer->handle());
        return $this;
    }

    /** Ligne 0-indexée (DBGp compte à partir de 1 : soustraire côté appelant). */
    public function moveCursorToLine(int $line): static
    {
        GtkText::moveCursorToLine($this->buffer->handle(), $line);
        return $this;
    }

    /**
     * Handler : function (CodeEditor $editor, int $keyval, int $state): bool
     * Retourner true consomme la touche.
     */
    public function onKeyPressed(callable $handler): static
    {
        if ($this->keyController === null) {
            $this->keyController = RawEventControllerKey::new();
            $this->raw()->addController($this->keyController);
        }

        $this->keyController->onKeyPressed(
            fn ($controller, int $keyval, int $keycode, int $state): bool
                => (bool) $handler($this, $keyval, $state),
        );

        return $this;
    }

    public function charCount(): int
    {
        return $this->buffer->getCharCount();
    }

    public function lineCount(): int
    {
        return $this->buffer->getLineCount();
    }

    public function editable(bool $editable = true): static
    {
        $this->raw()->setEditable($editable);
        return $this;
    }
}
