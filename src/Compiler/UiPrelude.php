<?php

declare(strict_types=1);

namespace Cyonima\Compiler;

/**
 * Façade objet minimale de l’UI native.
 *
 * Ce code est analysé uniquement lorsqu’un programme instancie l’une des
 * classes ci-dessous. Les widgets restent identifiés par un handle entier ;
 * les compositions utilisent explicitement `$widget->handle` dans cette
 * première version.
 */
final class UiPrelude
{
    public const SOURCE = <<<'PHP'
<?php

class Application
{
    public int $handle;

    public function __construct(string $appId)
    {
        ui_init($appId);
        $this->handle = 0;
    }

    public function run(): int
    {
        return ui_run();
    }

    public function quit(): void
    {
        ui_quit();
    }

    public function after(int $milliseconds, callable():void $callback): void
    {
        ui_after_closure($milliseconds, $callback);
    }
}

class Window
{
    public int $handle;

    public function __construct(string $title, int $width, int $height)
    {
        $this->handle = ui_window($title, $width, $height);
    }

    public function setChild(int $child): void
    {
        ui_set_child($this->handle, $child);
    }

    public function present(): void
    {
        ui_present($this->handle);
    }

    public function setVisible(bool $visible): void
    {
        ui_set_visible($this->handle, $visible);
    }
}

class Box
{
    public int $handle;

    public function __construct(bool $vertical, int $spacing)
    {
        $this->handle = ui_box($vertical, $spacing);
    }

    public function append(int $child): void
    {
        ui_append($this->handle, $child);
    }

    public function setVisible(bool $visible): void
    {
        ui_set_visible($this->handle, $visible);
    }
}

class Label
{
    public int $handle;

    public function __construct(string $text)
    {
        $this->handle = ui_label($text);
    }

    public function setText(string $text): void
    {
        ui_set_text($this->handle, $text);
    }

    public function setVisible(bool $visible): void
    {
        ui_set_visible($this->handle, $visible);
    }
}

class Button
{
    public int $handle;

    public function __construct(string $text)
    {
        $this->handle = ui_button($text);
    }

    public function onClicked(callable():void $callback): void
    {
        ui_on_click_closure($this->handle, $callback);
    }

    public function setVisible(bool $visible): void
    {
        ui_set_visible($this->handle, $visible);
    }
}

class Entry
{
    public int $handle;

    public function __construct(string $placeholder)
    {
        $this->handle = ui_entry($placeholder);
    }

    public function text(): string
    {
        return ui_entry_text($this->handle);
    }

    public function onActivate(callable():void $callback): void
    {
        ui_on_activate_closure($this->handle, $callback);
    }

    public function setVisible(bool $visible): void
    {
        ui_set_visible($this->handle, $visible);
    }
}
PHP;
}
