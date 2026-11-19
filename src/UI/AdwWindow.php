<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Adw\Raw\Application as RawApplication;
use Cyonima\Adw\Raw\ApplicationWindow;
use Cyonima\Adw\Raw\HeaderBar;
use Cyonima\Adw\Raw\Toast;
use Cyonima\Adw\Raw\ToastOverlay;
use Cyonima\Adw\Raw\ToolbarView;
use Cyonima\Adw\Raw\WindowTitle;
use Cyonima\Gtk\Raw\CssProvider;
use Cyonima\Gtk\Raw\StyleContext;
use LogicException;

/**
 * Fenêtre libadwaita : barre de titre native, contenu avec toasts, CSS.
 *
 * La fenêtre est construite sur `AdwApplicationWindow` + `AdwToolbarView`,
 * qui est la structure attendue par Adwaita (contrairement à un Gtk.Window
 * classique, `set_titlebar()` y est interdit).
 */
final class AdwWindow
{
    private ApplicationWindow $raw;
    private ToolbarView $toolbar;
    private HeaderBar $header;
    private WindowTitle $titleWidget;
    private ?ToastOverlay $overlay = null;

    public function __construct(RawApplication $application, string $title = '', string $subtitle = '')
    {
        $this->raw = ApplicationWindow::create($application);
        $this->toolbar = ToolbarView::new();
        $this->header = HeaderBar::new();
        $this->titleWidget = WindowTitle::new($title, $subtitle);

        $this->header->setTitleWidget($this->titleWidget);
        $this->toolbar->addTopBar($this->header);
        $this->raw->setContent($this->toolbar);
    }

    public function raw(): ApplicationWindow
    {
        return $this->raw;
    }

    public function title(string $title): static
    {
        $this->titleWidget->setTitle($title);
        return $this;
    }

    public function subtitle(string $subtitle): static
    {
        $this->titleWidget->setSubtitle($subtitle);
        return $this;
    }

    /** Place le contenu principal ; l'enveloppe dans un ToastOverlay. */
    public function content(Widget $content): static
    {
        $this->overlay = ToastOverlay::new();
        $this->overlay->setChild($content->raw());
        $this->toolbar->setContent($this->overlay);
        return $this;
    }

    public function headerStart(Widget ...$widgets): static
    {
        foreach ($widgets as $widget) {
            $this->header->packStart($widget->raw());
        }
        return $this;
    }

    public function headerEnd(Widget ...$widgets): static
    {
        foreach ($widgets as $widget) {
            $this->header->packEnd($widget->raw());
        }
        return $this;
    }

    public function toast(string $message, int $timeoutMs = 3000): static
    {
        if ($this->overlay === null) {
            throw new LogicException('Appeler content() avant toast().');
        }

        $toast = Toast::new($message);
        $toast->setTimeout($timeoutMs);
        $this->overlay->addToast($toast);

        return $this;
    }

    public function onCloseRequest(callable $handler): static
    {
        $this->raw->onCloseRequest(fn () => $handler($this));
        return $this;
    }

    public function present(): static
    {
        $this->raw->present();
        return $this;
    }

    /** Applique une feuille CSS à toute l'application. */
    public function addCss(string $css): static
    {
        $provider = CssProvider::new();
        $provider->loadFromString($css);

        $display = $this->raw->getDisplay();
        if ($display !== null) {
            StyleContext::addProviderForDisplay($display, $provider->handle(), 600);
        }

        return $this;
    }
}
