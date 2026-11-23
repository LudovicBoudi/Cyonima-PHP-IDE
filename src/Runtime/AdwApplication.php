<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

use Cyonima\Adw\Raw\Application as RawApplication;
use Cyonima\UI\AdwWindow;
use LogicException;

/**
 * Application libadwaita.
 *
 * Contrairement à `Application` (GTK nu), elle enregistre un vrai
 * `Adw.Application` afin qu'`Adw.ApplicationWindow` puisse être créé. La boucle
 * d'événements reste celle du runtime : `g_application_run()` n'est jamais
 * appelé.
 */
final class AdwApplication
{
    private RawApplication $raw;

    /** @var list<AdwWindow> */
    private array $windows = [];

    private string $css = '';

    public function __construct(public readonly string $id = 'com.cyonima.application')
    {
        // G_APPLICATION_NON_UNIQUE (1 << 5) : chaque instance est autonome.
        // Sans cela, une seconde instance devient « distante » et
        // gtk_window_set_application() plante en créant sa fenêtre.
        $this->raw = RawApplication::new($id, 32);
        GApplication::register($this->raw->handle());
    }

    public function raw(): RawApplication
    {
        return $this->raw;
    }

    public function window(string $title = '', ?string $subtitle = null): AdwWindow
    {
        $window = new AdwWindow($this->raw, $title, $subtitle ?? '');

        if ($this->css !== '') {
            $window->addCss($this->css);
        }

        $window->onCloseRequest(function (AdwWindow $closed): void {
            $this->forget($closed);
        });

        $this->windows[] = $window;

        return $window;
    }

    public function addCss(string $css): static
    {
        $this->css .= "\n" . $css;
        foreach ($this->windows as $window) {
            $window->addCss($css);
        }
        return $this;
    }

    public function onError(callable $handler): static
    {
        Errors::handler($handler);
        return $this;
    }

    /** @return list<AdwWindow> */
    public function windows(): array
    {
        return $this->windows;
    }

    public function run(): int
    {
        if ($this->windows === []) {
            throw new LogicException('AdwApplication::run() appelé sans fenêtre.');
        }

        Kernel::boot();
        Loop::run();

        Scheduler::reset();
        Process::terminateAll();

        return 0;
    }

    public function quit(): void
    {
        Loop::quit();
    }

    private function forget(AdwWindow $window): void
    {
        $this->windows = array_values(array_filter(
            $this->windows,
            static fn (AdwWindow $candidate): bool => $candidate !== $window,
        ));

        if ($this->windows === []) {
            Loop::quit();
        }
    }
}
