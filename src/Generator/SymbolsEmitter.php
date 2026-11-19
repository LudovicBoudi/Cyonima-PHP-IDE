<?php

declare(strict_types=1);

namespace Cyonima\Generator;

/** Émet la classe Symbols : l'unique point d'entrée vers libffi. */
final class SymbolsEmitter
{
    /**
     * @param list<string> $declarations lignes de prototypes C (sans le point-virgule final)
     */
    public function __construct(
        private readonly string $rawNamespace,
        private readonly string $library,
        private readonly string $version,
        private readonly array $declarations,
    ) {
    }

    public function emit(): string
    {
        $declarations = $this->declarations;
        sort($declarations);

        $cdef = implode("\n", [
            'typedef int (*CyonimaSignalHandler)(void*, void*, void*, void*, void*);',
            '',
            'int           gtk_init_check(void);',
            'void         *g_object_ref_sink(void *object);',
            'void          g_object_unref(void *object);',
            'unsigned long g_signal_connect_data(void *instance, const char *signal, CyonimaSignalHandler handler, void *data, void *destroy, int flags);',
            'int           g_signal_emit_by_name(void *instance, const char *signal, ...);',
            '',
            ...$declarations,
        ]);

        return implode("\n", [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            '/**',
            ' * Fichier généré depuis la spec des bindings — ne pas éditer.',
            ' * Régénération : php bin/cyonima-gen generate',
            ' */',
            '',
            'namespace ' . $this->rawNamespace . ';',
            '',
            'final class Symbols',
            '{',
            "    private const LIBRARY = '" . $this->library . "';",
            '',
            '    private const CDEF = <<<\'C\'',
            $cdef,
            'C;',
            '',
            '    private static ?\\FFI $instance = null;',
            '    private static bool $initialized = false;',
            '',
            '    public static function get(): \\FFI',
            '    {',
            '        return self::$instance ??= \\FFI::cdef(self::CDEF, self::LIBRARY);',
            '    }',
            '',
            '    /** Initialise GTK une seule fois, avant toute création de widget. */',
            '    public static function ensureInitialized(): void',
            '    {',
            '        if (self::$initialized) {',
            '            return;',
            '        }',
            '        self::$initialized = true;',
            '',
            '        if (self::get()->gtk_init_check() === 0) {',
            "            throw new \\RuntimeException(\"GTK4 : impossible d'initialiser le display (DISPLAY/Wayland absents ?)\");",
            '        }',
            '    }',
            '}',
            '',
        ]);
    }
}
