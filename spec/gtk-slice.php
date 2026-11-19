<?php

/**
 * Tranche verticale M1 : surface minimale mais réelle pour construire une
 * application GTK4 native depuis PHP.
 *
 * Chaque méthode listée est vérifiée contre le typelib au moment de la
 * génération : une API qui bouge fait échouer le build au lieu de produire
 * silencieusement du code faux.
 */

declare(strict_types=1);

return [
    'output' => 'src',

    'namespaces' => [
        'Gtk' => [
            'version' => '4.0',
            'library' => 'libgtk-4.so.1',
            'raw_namespace' => 'Cyonima\\Gtk\\Raw',
        ],
    ],

    'classes' => [
        'Gtk\\Widget' => [
            'methods' => [
                'add_css_class' => ['cssClass'],
                'remove_css_class' => ['cssClass'],
                'set_visible' => ['visible'],
                'set_sensitive' => ['sensitive'],
                'set_hexpand' => ['expand'],
                'set_vexpand' => ['expand'],
                'set_halign' => ['align'],
                'set_valign' => ['align'],
                'set_margin_top' => ['margin'],
                'set_margin_bottom' => ['margin'],
                'set_margin_start' => ['margin'],
                'set_margin_end' => ['margin'],
                'set_size_request' => ['width', 'height'],
                'set_tooltip_text' => ['text'],
                'get_display' => [],
                'get_width' => [],
                'get_height' => [],
                'get_parent' => [],
            ],
            'signals' => false,
        ],

        'Gtk\\Window' => [
            'methods' => [
                'new' => [],
                'set_title' => ['title'],
                'get_title' => [],
                'set_default_size' => ['width', 'height'],
                'set_child' => ['child'],
                'get_child' => [],
                'present' => [],
                'close' => [],
                'destroy' => [],
                'set_resizable' => ['resizable'],
                'set_decorated' => ['decorated'],
                'set_modal' => ['modal'],
                'maximize' => [],
            ],
            'signals' => ['close-request'],
        ],

        'Gtk\\Box' => [
            'methods' => [
                'new' => ['orientation', 'spacing'],
                'append' => ['child'],
                'remove' => ['child'],
                'set_spacing' => ['spacing'],
                'get_spacing' => [],
                'set_homogeneous' => ['homogeneous'],
                'get_homogeneous' => [],
            ],
            'signals' => false,
        ],

        'Gtk\\Label' => [
            'methods' => [
                'new' => ['text'],
                'set_text' => ['text'],
                'get_text' => [],
                'set_xalign' => ['xalign'],
                'set_yalign' => ['yalign'],
                'set_wrap' => ['wrap'],
                'set_selectable' => ['selectable'],
                'set_markup' => ['markup'],
            ],
            'signals' => false,
        ],

        'Gtk\\Button' => [
            'methods' => [
                'new_with_label' => ['label'],
                'set_label' => ['label'],
                'get_label' => [],
                'set_has_frame' => ['hasFrame'],
            ],
            'signals' => ['clicked'],
        ],

        'Gtk\\CssProvider' => [
            'methods' => [
                'new' => [],
                'load_from_string' => ['css'],
            ],
            'signals' => false,
        ],

        'Gtk\\StyleContext' => [
            'methods' => [
                'add_provider_for_display' => ['display', 'provider', 'priority'],
                'remove_provider_for_display' => ['display', 'provider'],
            ],
            'signals' => false,
        ],
    ],

    'enums' => [
        'Gtk\\Orientation' => true,
        'Gtk\\Align' => true,
    ],
];
