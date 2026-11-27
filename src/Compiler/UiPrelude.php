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

    public function addClass(string $class): void
    {
        ui_add_class($this->handle, $class);
    }

    public function setName(string $name): void
    {
        ui_set_name($this->handle, $name);
    }

    public function setSize(int $width, int $height): void
    {
        ui_set_size($this->handle, $width, $height);
    }

    public function setTooltip(string $text): void
    {
        ui_set_tooltip($this->handle, $text);
    }

    public function setSensitive(bool $sensitive): void
    {
        ui_set_sensitive($this->handle, $sensitive);
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

    public function loadCss(string $css): void
    {
        ui_add_css($css);
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

    public function loadCss(string $css): void
    {
        ui_add_css($css);
    }

    public function shortcut(string $accelerator, callable $callback): void
    {
        ui_shortcut($this->handle, $accelerator, $callback);
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

    public function text(): string
    {
        return ui_label_text($this->handle);
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

    public function setText(string $text): void
    {
        ui_entry_set_text($this->handle, $text);
    }

    public function activate(): void
    {
        ui_activate_entry($this->handle);
    }

    public function onActivate(callable $callback): void
    {
        ui_on_activate_closure($this->handle, $callback);
    }

    public function onChanged(callable $callback): void
    {
        ui_on_entry_changed_closure($this->handle, $callback);
    }
}

class CheckButton extends Widget
{
    public function __construct(string $label, bool $active)
    {
        $this->handle = ui_check_button($label);
        ui_set_check_active($this->handle, $active);
    }

    public function active(): bool
    {
        return ui_check_active($this->handle);
    }

    public function setActive(bool $active): void
    {
        ui_set_check_active($this->handle, $active);
    }

    public function onToggled(callable $callback): void
    {
        ui_on_toggle_closure($this->handle, $callback);
    }
}

class ProgressBar extends Widget
{
    public function __construct()
    {
        $this->handle = ui_progress_bar();
    }

    public function fraction(): float
    {
        return ui_progress_fraction($this->handle);
    }

    public function setFraction(float $fraction): void
    {
        ui_set_progress_fraction($this->handle, $fraction);
    }
}

class Separator extends Widget
{
    public function __construct(bool $vertical)
    {
        $this->handle = ui_separator($vertical);
    }
}

class Popover extends Widget
{
    public function __construct()
    {
        $this->handle = ui_popover();
    }

    public function setChild(Widget $child): void
    {
        ui_popover_set_child($this->handle, $child->handle);
    }
}

class MenuButton extends Widget
{
    public function __construct(string $label)
    {
        $this->handle = ui_menu_button($label);
    }

    public function setLabel(string $label): void
    {
        ui_menu_button_set_label($this->handle, $label);
    }

    public function setPopover(Popover $popover): void
    {
        ui_menu_button_set_popover($this->handle, $popover->handle);
    }

    public function popup(): void
    {
        ui_menu_button_popup($this->handle);
    }

    public function popdown(): void
    {
        ui_menu_button_popdown($this->handle);
    }
}

final class Menu
{
    public int $handle;

    /** @var array<int> */
    public array $actions;

    public function __construct()
    {
        $this->handle = ui_menu();
        $this->actions = [];
    }

    public function addItem(string $label, callable $callback): void
    {
        $index = ui_menu_add_item($this->handle, $label, $callback);
        $actions = $this->actions;
        $actions[] = $index;
        $this->actions = $actions;
    }

    public function addStyledItem(
        string $label,
        string $accelerator,
        string $icon,
        callable $callback
    ): void {
        $index = ui_menu_add_item_ex(
            $this->handle,
            $label,
            $accelerator,
            $icon,
            $callback
        );
        $actions = $this->actions;
        $actions[] = $index;
        $this->actions = $actions;
    }

    public function addCheckItem(
        string $label,
        bool $checked,
        string $accelerator,
        string $icon,
        callable $callback
    ): void {
        $index = ui_menu_add_check_item(
            $this->handle,
            $label,
            $checked,
            $accelerator,
            $icon,
            $callback
        );
        $actions = $this->actions;
        $actions[] = $index;
        $this->actions = $actions;
    }

    public function addRadioItem(
        string $group,
        string $label,
        string $value,
        bool $checked,
        string $accelerator,
        string $icon,
        callable $callback
    ): void {
        $index = ui_menu_add_radio_item(
            $this->handle,
            $group,
            $label,
            $value,
            $checked,
            $accelerator,
            $icon,
            $callback
        );
        $actions = $this->actions;
        $actions[] = $index;
        $this->actions = $actions;
    }

    public function setEnabled(int $position, bool $enabled): void
    {
        $actions = $this->actions;

        if ($position < 0 || $position >= count($actions)) {
            return;
        }

        ui_menu_set_enabled($actions[$position], $enabled);
    }

    public function isEnabled(int $position): bool
    {
        $actions = $this->actions;

        if ($position < 0 || $position >= count($actions)) {
            return false;
        }

        return ui_menu_is_enabled($actions[$position]);
    }

    public function setRadio(int $position): void
    {
        $actions = $this->actions;

        if ($position < 0 || $position >= count($actions)) {
            return;
        }

        ui_menu_select_radio($actions[$position]);
    }

    public function setChecked(int $position, bool $checked): void
    {
        $actions = $this->actions;

        if ($position < 0 || $position >= count($actions)) {
            return;
        }

        ui_menu_set_check($actions[$position], $checked);
    }

    public function addSeparator(): void
    {
        ui_menu_add_separator($this->handle);
    }

    public function addSubmenu(string $label, Menu $menu): void
    {
        ui_menu_add_submenu($this->handle, $label, $menu->handle);
    }

    public function activate(int $index): void
    {
        $actions = $this->actions;

        if ($index < 0 || $index >= count($actions)) {
            return;
        }

        ui_menu_activate($actions[$index]);
    }
}

class MenuBar extends Widget
{
    public int $root;

    public function __construct()
    {
        $this->root = ui_menu();
        $this->handle = ui_menu_bar($this->root);
    }

    public function add(string $label, Menu $menu): void
    {
        ui_menu_add_submenu($this->root, $label, $menu->handle);
    }
}

class Image extends Widget
{
    public function __construct(string $path)
    {
        $this->handle = ui_image($path);
    }
}

class ScrolledWindow extends Widget
{
    public function __construct()
    {
        $this->handle = ui_scrolled();
    }

    public function setChild(Widget $child): void
    {
        ui_scrolled_set_child($this->handle, $child->handle);
    }
}

interface Listable
{
    public function listLabel(): string;
}

final class ObjectList
{
    /** @var array<Listable> */
    public array $items;

    /** @param array<Listable> $items */
    public function __construct(array $items)
    {
        $this->items = $items;
    }

    public function add(Listable $item): void
    {
        $items = $this->items;
        $items[] = $item;
        $this->items = $items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function item(int $index): ?Listable
    {
        $items = $this->items;
        $count = count($items);

        if ($index < 0 || $index >= $count) {
            return null;
        }

        return $items[$index];
    }

    /** @return array<string> */
    public function labels(): array
    {
        $labels = [];
        $items = $this->items;

        foreach ($items as $item) {
            $labels[] = $item->listLabel();
        }

        return $labels;
    }
}

class StringList
{
    public int $handle;
    /** @param array<string> $items */
    public function __construct(array $items)
    {
        $this->handle = ui_string_list();

        foreach ($items as $item) {
            ui_string_list_append($this->handle, $item);
        }
    }

    public function append(string $item): void
    {
        ui_string_list_append($this->handle, $item);
    }

    public function count(): int
    {
        return ui_string_list_count($this->handle);
    }

    public function item(int $index): string
    {
        return ui_string_list_item($this->handle, $index);
    }

    public function onChanged(callable():void $callback): void
    {
        ui_string_list_on_changed($this->handle, $callback);
    }

    public function onIndexChanged(callable(int):void $callback): void
    {
        ui_string_list_on_changed($this->handle, $callback);
    }
}

class DropDown extends Widget
{
    public StringList $model;
    public ?ObjectList $objects;

    public function __construct(StringList $model, int $selected)
    {
        $this->model = $model;
        $this->objects = null;
        $this->handle = ui_drop_down($model->handle);
        ui_set_drop_down_selected($this->handle, $selected);
    }

    public static function fromObjects(ObjectList $items, int $selected): DropDown
    {
        $labels = $items->labels();
        $dropdown = new DropDown(new StringList($labels), $selected);
        $dropdown->objects = $items;

        return $dropdown;
    }

    public function selectedIndex(): int
    {
        return ui_drop_down_selected($this->handle);
    }

    public function selectedText(): string
    {
        return ui_drop_down_text($this->handle);
    }

    public function selectedObject(): ?Listable
    {
        $objects = $this->objects;

        if ($objects === null) {
            return null;
        }

        return $objects->item($this->selectedIndex());
    }

    public function setSelected(int $index): void
    {
        ui_set_drop_down_selected($this->handle, $index);
    }

    public function selectText(string $text): void
    {
        $count = $this->model->count();

        for ($index = 0; $index < $count; $index = $index + 1) {
            if ($this->model->item($index) === $text) {
                $this->setSelected($index);

                return;
            }
        }
    }

    public function onChanged(callable $callback): void
    {
        ui_on_drop_down_change_closure($this->handle, $callback);
    }
}

class ListBox extends Widget
{
    public function __construct()
    {
        $this->handle = ui_list_box();
    }

    public function append(Widget $child): void
    {
        ui_list_box_append($this->handle, $child->handle);
    }

    public function appendObject(Listable $item): void
    {
        $this->append(new Label($item->listLabel()));
    }

    public function appendObjects(ObjectList $items): void
    {
        $objects = $items->items;

        foreach ($objects as $item) {
            $this->appendObject($item);
        }
    }

    public function selectedIndex(): int
    {
        return ui_list_box_selected($this->handle);
    }

    public function selectIndex(int $index): void
    {
        ui_list_box_select($this->handle, $index);
    }

    public function setSelectionMode(string $mode): void
    {
        $value = 0;

        if ($mode === 'none') {
            $value = 1;
        } elseif ($mode === 'multiple') {
            $value = 2;
        }

        ui_list_box_mode($this->handle, $value);
    }

    public function activate(int $index): void
    {
        ui_list_box_activate($this->handle, $index);
    }

    public function onRowActivated(callable $callback): void
    {
        ui_on_row_activated_closure($this->handle, $callback);
    }
}

class ListView extends Widget
{
    public StringList $model;
    public ?ObjectList $objects;

    public function __construct(StringList $model, int $selected)
    {
        $this->model = $model;
        $this->objects = null;
        $this->handle = ui_list_view($model->handle, $selected);
    }

    public static function fromObjects(ObjectList $items, int $selected): ListView
    {
        $labels = $items->labels();
        $view = new ListView(new StringList($labels), $selected);
        $view->objects = $items;

        return $view;
    }

    public function selectedIndex(): int
    {
        return ui_list_view_selected($this->handle);
    }

    public function selectedText(): string
    {
        $index = $this->selectedIndex();
        $count = $this->model->count();

        if ($index < 0 || $index >= $count) {
            return '';
        }

        return $this->model->item($index);
    }

    public function selectIndex(int $index): void
    {
        ui_list_view_select($this->handle, $index);
    }

    public function selectText(string $text): void
    {
        $count = $this->model->count();

        for ($index = 0; $index < $count; $index = $index + 1) {
            if ($this->model->item($index) === $text) {
                $this->selectIndex($index);

                return;
            }
        }
    }

    public function selectedObject(): ?Listable
    {
        $objects = $this->objects;
        $index = $this->selectedIndex();

        if ($objects === null || $index < 0) {
            return null;
        }

        return $objects->item($index);
    }

    public function setSingleClick(bool $single): void
    {
        ui_list_view_set_single_click($this->handle, $single);
    }

    public function activate(int $index): void
    {
        ui_list_view_activate($this->handle, $index);
    }

    public function onActivated(callable $callback): void
    {
        ui_on_list_view_activated_closure($this->handle, $callback);
    }
}

final class Binding
{
    public callable():void $refreshCallback;

    public function __construct(callable():void $refreshCallback)
    {
        $this->refreshCallback = $refreshCallback;
    }

    public function refresh(): void
    {
        $callback = $this->refreshCallback;
        $callback();
    }

    public static function entry(
        Entry $entry,
        callable():string $getter,
        callable(string):void $setter
    ): Binding {
        $entry->onChanged(function (string $value) use ($setter): void {
            $setter($value);
        });

        $binding = new Binding(function () use ($entry, $getter): void {
            $entry->setText($getter());
        });
        $binding->refresh();

        return $binding;
    }

    public static function label(Label $label, callable():string $getter): Binding
    {
        $binding = new Binding(function () use ($label, $getter): void {
            $label->setText($getter());
        });
        $binding->refresh();

        return $binding;
    }

    public static function check(
        CheckButton $check,
        callable():bool $getter,
        callable(bool):void $setter
    ): Binding {
        $check->onToggled(function (bool $active) use ($setter): void {
            $setter($active);
        });

        $binding = new Binding(function () use ($check, $getter): void {
            $check->setActive($getter());
        });
        $binding->refresh();

        return $binding;
    }

    public static function dropIndex(
        DropDown $drop,
        callable():int $getter,
        callable(int):void $setter
    ): Binding {
        $drop->onChanged(function (int $index) use ($setter): void {
            $setter($index);
        });

        $refresh = function () use ($drop, $getter): void {
            $drop->setSelected($getter());
        };
        $drop->model->onChanged(function () use ($refresh): void {
            $refresh();
        });

        $binding = new Binding($refresh);
        $binding->refresh();

        return $binding;
    }

    public static function dropText(
        DropDown $drop,
        callable():string $getter,
        callable(string):void $setter
    ): Binding {
        $drop->onChanged(function (string $value) use ($setter): void {
            $setter($value);
        });

        $refresh = function () use ($drop, $getter): void {
            $drop->selectText($getter());
        };
        $drop->model->onChanged(function () use ($refresh): void {
            $refresh();
        });

        $binding = new Binding($refresh);
        $binding->refresh();

        return $binding;
    }

    public static function listIndex(
        ListBox $list,
        callable():int $getter,
        callable(int):void $setter
    ): Binding {
        $list->onRowActivated(function (int $index) use ($setter): void {
            $setter($index);
        });

        $binding = new Binding(function () use ($list, $getter): void {
            $list->selectIndex($getter());
        });
        $binding->refresh();

        return $binding;
    }

    public static function viewIndex(
        ListView $view,
        callable():int $getter,
        callable(int):void $setter
    ): Binding {
        $view->onActivated(function (int $index) use ($setter): void {
            $setter($index);
        });

        $refresh = function () use ($view, $getter): void {
            $view->selectIndex($getter());
        };
        $view->model->onChanged(function () use ($refresh): void {
            $refresh();
        });

        $binding = new Binding($refresh);
        $binding->refresh();

        return $binding;
    }

    public static function viewText(
        ListView $view,
        callable():string $getter,
        callable(string):void $setter
    ): Binding {
        $view->onActivated(function (int $index) use ($view, $setter): void {
            $view->selectIndex($index);
            $setter($view->selectedText());
        });

        $refresh = function () use ($view, $getter): void {
            $view->selectText($getter());
        };
        $view->model->onChanged(function () use ($refresh): void {
            $refresh();
        });

        $binding = new Binding($refresh);
        $binding->refresh();

        return $binding;
    }
}
PHP;
}
