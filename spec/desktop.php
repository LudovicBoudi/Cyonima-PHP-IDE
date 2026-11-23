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
        'GtkSource' => [
            'version' => '5',
            'library' => 'libgtksourceview-5.so.0',
            'raw_namespace' => 'Cyonima\\GtkSource\\Raw',
        ],
        'Vte' => [
            'version' => '3.91',
            'library' => 'libvte-2.91-gtk4.so.0',
            'raw_namespace' => 'Cyonima\\Vte\\Raw',
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
                'get_visible' => [],
                'add_controller' => ['controller'],
                'set_parent' => ['parent'],
            ],
            'signals' => false,
        ],

        'Gtk\\EventController' => [
            'methods' => [],
            'signals' => false,
        ],

        'Gtk\\EventControllerKey' => [
            'methods' => [
                'new' => [],
            ],
            'signals' => ['key-pressed'],
        ],

        'Gtk\\Popover' => [
            'methods' => [
                'new' => [],
                'set_child' => ['child'],
                'set_has_arrow' => ['hasArrow'],
                'set_autohide' => ['autohide'],
                'popup' => [],
                'popdown' => [],
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

        'Gtk\\TextView' => [
            'methods' => [
                'get_buffer' => [],
                'set_monospace' => ['monospace'],
                'set_editable' => ['editable'],
            ],
            'signals' => false,
        ],

        'Gtk\\TextBuffer' => [
            'methods' => [
                'set_text' => ['text', 'length'],
                'get_char_count' => [],
                'get_line_count' => [],
                'set_modified' => ['modified'],
            ],
            'signals' => false,
        ],

        'Gtk\\ScrolledWindow' => [
            'methods' => [
                'new' => [],
                'set_child' => ['child'],
            ],
            'signals' => false,
        ],

        'Gtk\\ListBox' => [
            'methods' => [
                'new' => [],
                'append' => ['child'],
                'remove' => ['child'],
                'remove_all' => [],
                'select_row' => ['row'],
                'get_selected_row' => [],
                'set_selection_mode' => ['mode'],
                'get_activate_on_single_click' => [],
                'set_activate_on_single_click' => ['activateOnSingleClick'],
            ],
            'signals' => ['row-activated'],
        ],

        'Gtk\\ListBoxRow' => [
            'methods' => [
                'new' => [],
                'set_child' => ['child'],
                'get_child' => [],
                'set_activatable' => ['activatable'],
                'set_selectable' => ['selectable'],
                'get_index' => [],
            ],
            'signals' => ['activate'],
        ],

        'Gtk\\Paned' => [
            'methods' => [
                'new' => ['orientation'],
                'set_start_child' => ['child'],
                'set_end_child' => ['child'],
                'set_position' => ['position'],
                'set_resize_start_child' => ['resize'],
                'set_shrink_start_child' => ['shrink'],
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
        // --- GtkSourceView 5 -----------------------------------------------
        'GtkSource\\View' => [
            'methods' => [
                'new' => [],
                'new_with_buffer' => ['buffer'],
                'set_show_line_numbers' => ['showLineNumbers'],
                'set_highlight_current_line' => ['highlightCurrentLine'],
                'set_tab_width' => ['tabWidth'],
                'set_auto_indent' => ['autoIndent'],
                'set_indent_width' => ['indentWidth'],
                'set_insert_spaces_instead_of_tabs' => ['insertSpaces'],
                'set_show_right_margin' => ['showRightMargin'],
                'set_right_margin_position' => ['rightMarginPosition'],
                'get_show_line_numbers' => [],
            ],
            'signals' => false,
        ],

        'GtkSource\\Buffer' => [
            'methods' => [
                'new' => ['tagTable'],
                'set_language' => ['language'],
                'get_language' => [],
                'set_highlight_syntax' => ['highlightSyntax'],
                'get_highlight_syntax' => [],
                'set_style_scheme' => ['styleScheme'],
                'get_style_scheme' => [],
            ],
            'signals' => false,
        ],

        'GtkSource\\LanguageManager' => [
            'methods' => [
                'get_default' => [],
                'get_language' => ['languageId'],
            ],
            'signals' => false,
        ],

        'GtkSource\\StyleSchemeManager' => [
            'methods' => [
                'get_default' => [],
                'get_scheme' => ['schemeId'],
            ],
            'signals' => false,
        ],

        // --- VTE -----------------------------------------------------------
        'Vte\\Terminal' => [
            'methods' => [
                'new' => [],
                'set_scrollback_lines' => ['lines'],
            ],
            'signals' => ['child-exited'],
        ],
    ],

    'enums' => [
        'Gtk\\Orientation' => true,
        'Gtk\\Align' => true,
        'Adw\\CenteringPolicy' => true,
    ],
];
