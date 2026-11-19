<?php

/**
 * Surface de bindings de la tranche M3 : GTK4 + libadwaita.
 *
 * Chaque méthode listée est vérifiée contre le typelib au moment de la
 * génération : une API qui bouge fait échouer le build.
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
        'Adw' => [
            'version' => '1',
            'library' => 'libadwaita-1.so.0',
            'raw_namespace' => 'Cyonima\\Adw\\Raw',
            'init' => 'adw_init',
        ],
    ],

    'classes' => [
        // --- GTK -----------------------------------------------------------
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

        'Gtk\\Application' => [
            'methods' => [
                'new' => ['applicationId', 'flags'],
            ],
            'signals' => false,
        ],

        'Gtk\\ApplicationWindow' => [
            'methods' => [
                'new' => ['application'],
            ],
            'signals' => false,
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

        // --- libadwaita ----------------------------------------------------
        'Adw\\Application' => [
            'methods' => [
                'new' => ['applicationId', 'flags'],
            ],
            'signals' => false,
        ],

        'Adw\\ApplicationWindow' => [
            'methods' => [
                'new' => ['application'],
                'set_content' => ['content'],
                'get_content' => [],
            ],
            'signals' => false,
        ],

        'Adw\\HeaderBar' => [
            'methods' => [
                'new' => [],
                'pack_start' => ['child'],
                'pack_end' => ['child'],
                'set_title_widget' => ['titleWidget'],
                'set_show_back_button' => ['showBackButton'],
                'set_show_start_title_buttons' => ['showStartTitleButtons'],
                'set_show_end_title_buttons' => ['showEndTitleButtons'],
                'set_centering_policy' => ['centeringPolicy'],
            ],
            'signals' => false,
        ],

        'Adw\\ToolbarView' => [
            'methods' => [
                'new' => [],
                'add_top_bar' => ['widget'],
                'add_bottom_bar' => ['widget'],
                'set_content' => ['content'],
                'get_content' => [],
            ],
            'signals' => false,
        ],

        'Adw\\Clamp' => [
            'methods' => [
                'new' => [],
                'set_child' => ['child'],
                'set_maximum_size' => ['maximumSize'],
                'set_tightening_threshold' => ['tighteningThreshold'],
            ],
            'signals' => false,
        ],

        'Adw\\WindowTitle' => [
            'methods' => [
                'new' => ['title', 'subtitle'],
                'set_title' => ['title'],
                'set_subtitle' => ['subtitle'],
            ],
            'signals' => false,
        ],

        'Adw\\PreferencesPage' => [
            'methods' => [
                'new' => [],
                'add' => ['group'],
                'set_title' => ['title'],
                'set_description' => ['description'],
                'set_icon_name' => ['iconName'],
            ],
            'signals' => false,
        ],

        'Adw\\PreferencesGroup' => [
            'methods' => [
                'new' => [],
                'add' => ['row'],
                'set_title' => ['title'],
                'set_description' => ['description'],
            ],
            'signals' => false,
        ],

        'Adw\\PreferencesRow' => [
            'methods' => [
                'new' => [],
                'set_title' => ['title'],
                'set_title_selectable' => ['titleSelectable'],
            ],
            'signals' => false,
        ],

        'Adw\\ActionRow' => [
            'methods' => [
                'new' => [],
                'set_subtitle' => ['subtitle'],
                'get_subtitle' => [],
                'set_subtitle_lines' => ['subtitleLines'],
                'set_icon_name' => ['iconName'],
                'add_suffix' => ['suffix'],
            ],
            'signals' => ['activated'],
        ],

        'Adw\\SwitchRow' => [
            'methods' => [
                'new' => [],
                'set_active' => ['active'],
                'get_active' => [],
            ],
            'signals' => false,
        ],

        'Adw\\Toast' => [
            'methods' => [
                'new' => ['title'],
                'set_title' => ['title'],
                'set_timeout' => ['timeout'],
                'set_button_label' => ['buttonLabel'],
            ],
            'signals' => false,
        ],

        'Adw\\ToastOverlay' => [
            'methods' => [
                'new' => [],
                'set_child' => ['child'],
                'add_toast' => ['toast'],
            ],
            'signals' => false,
        ],

        'Adw\\StatusPage' => [
            'methods' => [
                'new' => [],
                'set_title' => ['title'],
                'set_description' => ['description'],
                'set_icon_name' => ['iconName'],
                'set_child' => ['child'],
            ],
            'signals' => false,
        ],
    ],

    'enums' => [
        'Gtk\\Orientation' => true,
        'Gtk\\Align' => true,
        'Adw\\CenteringPolicy' => true,
    ],
];
