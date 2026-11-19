<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

use FFI;
use FFI\CData;
use RuntimeException;

/**
 * Lecture du contenu d'un GtkTextBuffer.
 *
 * `gtk_text_buffer_get_text()` exige deux `GtkTextIter`, une struct publique
 * mais versionnée. Sa taille et son alignement sont connus via GObject
 * Introspection : 80 octets, alignement 8 pour GTK 4.x sur 64 bits. On déclare
 * donc un blob correctement aligné plutôt que de figer la liste des champs.
 */
final class GtkText
{
    private const ITER_SIZE = 80;

    private const CDEF = <<<'C'
        typedef struct { long _alignment; char data[72]; } CyonimaTextIter;

        void  gtk_text_buffer_get_start_iter(void *buffer, CyonimaTextIter *iter);
        void  gtk_text_buffer_get_end_iter(void *buffer, CyonimaTextIter *iter);
        char *gtk_text_buffer_get_text(
            void *buffer, const CyonimaTextIter *start, const CyonimaTextIter *end, int include_hidden);
        void  g_free(void *pointer);
        C;

    private static ?FFI $ffi = null;

    private static function ffi(): FFI
    {
        if (PHP_INT_SIZE !== 8) {
            throw new RuntimeException('GtkTextIter : plateforme 64 bits requise');
        }

        return self::$ffi ??= FFI::cdef(self::CDEF, 'libgtk-4.so.1');
    }

    public static function fromBuffer(CData $buffer): string
    {
        $ffi = self::ffi();

        $start = $ffi->new('CyonimaTextIter');
        $end = $ffi->new('CyonimaTextIter');

        $ffi->gtk_text_buffer_get_start_iter($buffer, FFI::addr($start));
        $ffi->gtk_text_buffer_get_end_iter($buffer, FFI::addr($end));

        $pointer = $ffi->gtk_text_buffer_get_text(
            $buffer,
            FFI::addr($start),
            FFI::addr($end),
            0,
        );

        if ($pointer === null) {
            return '';
        }

        $text = FFI::string($pointer);
        $ffi->g_free($pointer);

        return $text;
    }
}
