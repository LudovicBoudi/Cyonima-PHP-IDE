<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

use Cyonima\UI\Window;
use LogicException;

/**
 * Cycle de vie de l'application : fenêtres, CSS global, erreurs, tick runtime.
 *
 *   $app = new Application('com.example.demo');
 *   $app->window('Démo')->setChild($root)->present();
 *   $app->run();
 *
 * La boucle s'arrête quand la dernière fenêtre est fermée.
 */
final class Application
{
    /** @var list<Window> */
    private array $windows = [];

    private string $css = '';

    public function __construct(public readonly string $id = 'com.cyonima.application')
    {
    }

    public function window(string $title = '', int $width = 900, int $height = 600): Window
    {
        $window = new Window($title, $width, $height);

        if ($this->css !== '') {
            $window->addCss($this->css);
        }

        $window->onCloseRequest(function (Window $closed): void {
            $this->forget($closed);
        });

        $this->windows[] = $window;

        return $window;
    }

    /** Ajoute une feuille CSS globale, appliquée aux fenêtres existantes. */
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

    /** @return list<Window> */
    public function windows(): array
    {
        return $this->windows;
    }

    public function run(): int
    {
        if ($this->windows === []) {
            throw new LogicException('Application::run() appelé sans fenêtre.');
        }

        Kernel::boot();

        Loop::run();

        // Nettoyage : plus de boucle pour réveiller les tâches, et aucun
        // sous-processus orphelin.
        Scheduler::reset();
        Process::terminateAll();

        return 0;
    }

    public function quit(): void
    {
        Loop::quit();
    }


    private function forget(Window $window): void
    {
        $this->windows = array_values(array_filter(
            $this->windows,
            static fn (Window $candidate): bool => $candidate !== $window,
        ));

        if ($this->windows === []) {
            Loop::quit();
        }
    }
}
