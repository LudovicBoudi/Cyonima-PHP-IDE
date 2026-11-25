<?php

declare(strict_types=1);

namespace Cyonima\Compiler;

use Cyonima\Runtime\FormDocument;

/**
 * Générateur C pour un formulaire du concepteur.
 *
 * Le code produit utilise GTK4 directement : il ne dépend ni de PHP ni de la
 * couche FFI de Cyonima. C'est le premier backend du compilateur natif.
 */
final class CGenerator
{
    public function __construct(
        private readonly FormDocument $form,
        private readonly string $projectRoot,
        private readonly string $appId,
        private readonly string $appName,
    ) {
    }

    public function generate(): string
    {
        $handlers = '';
        $widgets = '';
        $events = '';

        foreach ($this->form->widgets() as $widget) {
            $variable = $this->identifier($widget['name']);
            $widgets .= $this->widgetCode($widget, $variable);
            $events .= $this->eventWiring($widget, $variable, $handlers);
        }

        $name = $this->cString($this->appName);
        $appId = $this->cString($this->appId);
        $width = $this->form->width();
        $height = $this->form->height();

        return <<<C
            /*
             * Formulaire natif généré par Cyonima IDE.
             * Modèle : .cyonima/forms/{$this->form->name()}.form.json
             *
             * Ce fichier est indépendant du runtime PHP : il utilise GTK4/C.
             */

            #include <gtk/gtk.h>
            #include <stdlib.h>

            {$handlers}

            static void activate(GtkApplication *app, gpointer user_data)
            {
                (void) user_data;

                GtkWidget *window = gtk_application_window_new(app);
                gtk_window_set_title(GTK_WINDOW(window), {$name});
                gtk_window_set_default_size(GTK_WINDOW(window), {$width}, {$height});

                GtkWidget *form = gtk_fixed_new();
                gtk_widget_set_hexpand(form, TRUE);
                gtk_widget_set_vexpand(form, TRUE);
                gtk_window_set_child(GTK_WINDOW(window), form);

            {$widgets}
            {$events}
                gtk_window_present(GTK_WINDOW(window));
            }

            int main(int argc, char **argv)
            {
                GtkApplication *app = gtk_application_new({$appId}, G_APPLICATION_DEFAULT_FLAGS);
                g_signal_connect(app, "activate", G_CALLBACK(activate), NULL);
                int status = g_application_run(G_APPLICATION(app), argc, argv);
                g_object_unref(app);
                return status;
            }
            C;
    }

    /** @param array{id: string, name: string, type: string, x: int, y: int, width: int, height: int, text: string, icon: string, events: list<string>} $widget */
    private function widgetCode(array $widget, string $variable): string
    {
        $x = $widget['x'];
        $y = $widget['y'];
        $width = $widget['width'];
        $height = $widget['height'];
        $text = $this->cString($widget['text']);
        $handler = $this->identifier($widget['name']);
        $lines = [];
        $placed = $variable;

        switch ($widget['type']) {
            case 'button':
                if ($widget['icon'] !== '') {
                    $lines[] = "    GtkWidget *{$variable} = gtk_button_new();";
                    $lines[] = "    GtkWidget *box_{$handler} = gtk_box_new(GTK_ORIENTATION_HORIZONTAL, 6);";
                    $lines[] = "    GtkWidget *icon_{$handler} = gtk_image_new_from_file({$this->cString($this->assetPath($widget['icon']))});";
                    $lines[] = "    gtk_image_set_pixel_size(GTK_IMAGE(icon_{$handler}), 20);";
                    $lines[] = "    gtk_widget_set_size_request(icon_{$handler}, 20, 20);";
                    $lines[] = "    gtk_box_append(GTK_BOX(box_{$handler}), icon_{$handler});";
                    if ($widget['text'] !== '') {
                        $lines[] = "    gtk_box_append(GTK_BOX(box_{$handler}), gtk_label_new({$text}));";
                    }
                    $lines[] = "    gtk_button_set_child(GTK_BUTTON({$variable}), box_{$handler});";
                } else {
                    $lines[] = "    GtkWidget *{$variable} = gtk_button_new_with_label({$text});";
                }
                break;

            case 'entry':
                $lines[] = "    GtkWidget *{$variable} = gtk_entry_new();";
                $lines[] = "    gtk_entry_set_placeholder_text(GTK_ENTRY({$variable}), {$text});";
                break;

            case 'switch':
                $lines[] = "    GtkWidget *{$variable} = gtk_switch_new();";
                $lines[] = "    GtkWidget *box_{$handler} = gtk_box_new(GTK_ORIENTATION_HORIZONTAL, 8);";
                $lines[] = "    gtk_box_append(GTK_BOX(box_{$handler}), gtk_label_new({$text}));";
                $lines[] = "    gtk_widget_set_hexpand({$variable}, TRUE);";
                $lines[] = "    gtk_widget_set_halign({$variable}, GTK_ALIGN_END);";
                $lines[] = "    gtk_box_append(GTK_BOX(box_{$handler}), {$variable});";
                $placed = "box_{$handler}";
                break;

            case 'image':
                $lines[] = "    GtkWidget *{$variable} = gtk_image_new_from_file({$this->cString($this->assetPath($widget['text']))});";
                break;

            case 'video':
                $lines[] = "    GtkWidget *{$variable} = gtk_video_new_for_filename({$this->cString($this->assetPath($widget['text']))});";
                break;

            case 'separator':
                $lines[] = "    GtkWidget *{$variable} = gtk_separator_new(GTK_ORIENTATION_HORIZONTAL);";
                break;

            default:
                $lines[] = "    GtkWidget *{$variable} = gtk_label_new({$text});";
                $lines[] = "    gtk_label_set_xalign(GTK_LABEL({$variable}), 0.0);";
                break;
        }

        $lines[] = "    gtk_widget_set_size_request({$placed}, {$width}, {$height});";
        $lines[] = "    gtk_fixed_put(GTK_FIXED(form), {$placed}, {$x}, {$y});";

        return implode("\n", $lines) . "\n";
    }

    /** @param array{id: string, name: string, type: string, text: string, events: list<string>} $widget */
    private function eventWiring(array $widget, string $variable, string &$handlers): string
    {
        $lines = [];
        $name = $this->identifier($widget['name']);

        foreach ($widget['events'] as $event) {
            $handler = "on_{$name}_" . str_replace('-', '_', $event);
            $signature = $this->handlerSignature($widget['type'], $event);
            $comment = "Action {$event} pour {$widget['name']} ({$widget['type']}).";
            $unused = '';

            foreach ($this->parameterNames($signature) as $parameter) {
                $unused .= "    (void) {$parameter};\n";
            }

            $handlers .= "\nstatic void {$handler}({$signature})\n{\n"
                . $unused
                . "    /* TODO : {$comment} */\n"
                . "}\n";

            if ($event === 'click' && $widget['type'] === 'button') {
                $lines[] = "    g_signal_connect({$variable}, \"clicked\", G_CALLBACK({$handler}), NULL);";
                continue;
            }

            if ($event === 'changed' && $widget['type'] === 'entry') {
                $lines[] = "    g_signal_connect({$variable}, \"changed\", G_CALLBACK({$handler}), NULL);";
                continue;
            }

            if ($event === 'activate' && $widget['type'] === 'entry') {
                $lines[] = "    g_signal_connect({$variable}, \"activate\", G_CALLBACK({$handler}), NULL);";
                continue;
            }

            if ($event === 'changed' && $widget['type'] === 'switch') {
                $lines[] = "    g_signal_connect({$variable}, \"notify::active\", G_CALLBACK({$handler}), NULL);";
                continue;
            }

            if ($event === 'click' || $event === 'double-click') {
                $gesture = "gesture_{$name}_" . str_replace('-', '_', $event);
                $lines[] = "    GtkGesture *{$gesture} = gtk_gesture_click_new();";
                $lines[] = "    g_signal_connect({$gesture}, \"pressed\", G_CALLBACK({$handler}), NULL);";
                $lines[] = "    gtk_widget_add_controller({$variable}, GTK_EVENT_CONTROLLER({$gesture}));";
            }
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    private function handlerSignature(string $type, string $event): string
    {
        if ($event === 'changed' && $type === 'switch') {
            return 'GObject *object, GParamSpec *pspec, gpointer user_data';
        }
        if ($event === 'changed') {
            return 'GtkEditable *editable, gpointer user_data';
        }
        if ($event === 'activate') {
            return 'GtkEntry *entry, gpointer user_data';
        }
        if ($event === 'click' && $type === 'button') {
            return 'GtkButton *button, gpointer user_data';
        }

        return 'GtkGestureClick *gesture, int n_press, double x, double y, gpointer user_data';
    }

    /** @return list<string> */
    private function parameterNames(string $signature): array
    {
        $names = [];

        foreach (explode(',', $signature) as $parameter) {
            $tokens = preg_split('/\s+/', trim($parameter)) ?: [];
            $name = ltrim((string) end($tokens), '*');

            if (is_string($name) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function assetPath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim($this->projectRoot, '/') . '/' . ltrim($path, '/');
    }

    private function identifier(string $name): string
    {
        $identifier = preg_replace('/[^A-Za-z0-9_]/', '_', $name) ?? 'widget';

        return 'w_' . $identifier;
    }

    private function cString(string $value): string
    {
        return '"' . str_replace(
            ["\\", '"', "\n", "\r", "\t"],
            ["\\\\", '\\"', '\\n', '\\r', '\\t'],
            $value,
        ) . '"';
    }
}
