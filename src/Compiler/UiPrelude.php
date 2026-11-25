<?php

declare(strict_types=1);

namespace Cyonima\Compiler;

/**
 * Façade objet minimale de l’UI native.
 *
 * Ce prélude est analysé uniquement lorsqu’un programme instancie l’une des
 * classes UI. Les widgets partagent la classe `Widget` et son handle ; les
 * compositions acceptent directement des objets Widget.
 */
final class UiPrelude
{
    public const SOURCE = <<<'PHP'
<?php

class Widget
{
    public int $handle;

    public function setVisible(bool $visible): void
    {
        ui_set_visible($this->handle, $visible);
    }
}

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

class Window extends Widget
{
    public function __construct(string $title, int $width, int $height)
    {
        $this->handle = ui_window($title, $width, $height);
    }

    public function setChild(Widget $child): void
    {
        ui_set_child($this->handle, $child->handle);
    }

    public function present(): void
    {
        ui_present($this->handle);
    }
}

class Box extends Widget
{
    public function __construct(bool $vertical, int $spacing)
    {
        $this->handle = ui_box($vertical, $spacing);
    }

    public function append(Widget $child): void
    {
        ui_append($this->handle, $child->handle);
    }
}

class Label extends Widget
{
    public function __construct(string $text)
    {
        $this->handle = ui_label($text);
    }

    public function setText(string $text): void
    {
        ui_set_text($this->handle, $text);
    }
}

class Button extends Widget
{
    public function __construct(string $text)
    {
        $this->handle = ui_button($text);
    }

    public function onClicked(callable():void $callback): void
    {
        ui_on_click_closure($this->handle, $callback);
    }

    public function click(): void
    {
        ui_click($this->handle);
    }
}

class Entry extends Widget
{
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
}
PHP;
}
