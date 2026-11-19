<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

use FFI;
use FFI\CData;
use RuntimeException;

/**
 * Accès au contenu et au curseur d'un GtkTextBuffer.
 *
 * `GtkTextIter` est une struct publique versionnée : 80 octets, alignement 8
 * pour GTK 4.x sur 64 bits (mesuré via GObject Introspection). On manipule donc
 * un blob correctement aligné plutôt que de figer la liste des champs.
 */
final class GtkText
{
    private const ITER_SIZE = 80;

    private const CDEF = <<<'C'
        typedef struct { long _alignment; char data[72]; } CyonimaTextIter;

        void         gtk_text_buffer_get_start_iter(void *buffer, CyonimaTextIter *iter);
        void         gtk_text_buffer_get_end_iter(void *buffer, CyonimaTextIter *iter);
        char        *gtk_text_buffer_get_text(
            void *buffer, const CyonimaTextIter *start, const CyonimaTextIter *end, int include_hidden);
        void         g_free(void *pointer);

        void        *gtk_text_buffer_get_insert(void *buffer);
        void         gtk_text_buffer_get_iter_at_mark(void *buffer, CyonimaTextIter *iter, void *mark);
        void         gtk_text_buffer_place_cursor(void *buffer, const CyonimaTextIter *iter);
        void         gtk_text_buffer_get_iter_at_line(void *buffer, CyonimaTextIter *iter, int line_number);
        int          gtk_text_iter_get_offset(const CyonimaTextIter *iter);
        int          gtk_text_iter_get_line(const CyonimaTextIter *iter);
        int          gtk_text_iter_get_line_offset(const CyonimaTextIter *iter);
        int          gtk_text_iter_backward_chars(CyonimaTextIter *iter, int count);
        unsigned int gtk_text_iter_get_char(const CyonimaTextIter *iter);

        void         gtk_text_buffer_delete(void *buffer, CyonimaTextIter *start, CyonimaTextIter *end);
        void         gtk_text_buffer_insert(void *buffer, CyonimaTextIter *iter, const char *text, int length);
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

        return self::textBetween($buffer, $start, $end);
    }

    /** @return array{offset: int, line: int, character: int} */
    public static function cursor(CData $buffer): array
    {
        $ffi = self::ffi();
        $iter = self::insertIter($buffer);

        return [
            'offset' => $ffi->gtk_text_iter_get_offset(FFI::addr($iter)),
            'line' => $ffi->gtk_text_iter_get_line(FFI::addr($iter)),
            'character' => $ffi->gtk_text_iter_get_line_offset(FFI::addr($iter)),
        ];
    }

    /** Préfixe de mot avant le curseur (`[A-Za-z0-9_$\\]`). */
    public static function wordPrefix(CData $buffer, int $maxLength = 256): string
    {
        $ffi = self::ffi();
        $end = self::insertIter($buffer);
        $probe = self::copy($end);

        $count = 0;
        while ($count < $maxLength
            && $ffi->gtk_text_iter_backward_chars(FFI::addr($probe), 1) !== 0
        ) {
            if (!self::isWordChar($ffi->gtk_text_iter_get_char(FFI::addr($probe)))) {
                break;
            }
            $count++;
        }

        $start = self::copy($end);
        if ($count > 0) {
            $ffi->gtk_text_iter_backward_chars(FFI::addr($start), $count);
        }

        return self::textBetween($buffer, $start, $end);
    }

    /** Remplace les N caractères avant le curseur par `$text`. */
    public static function replacePrefix(CData $buffer, int $prefixLength, string $text): void
    {
        $ffi = self::ffi();

        $insert = self::insertIter($buffer);
        $start = self::copy($insert);
        if ($prefixLength > 0) {
            $ffi->gtk_text_iter_backward_chars(FFI::addr($start), $prefixLength);
        }

        $ffi->gtk_text_buffer_delete($buffer, FFI::addr($start), FFI::addr($insert));

        $after = self::insertIter($buffer);
        $ffi->gtk_text_buffer_insert($buffer, FFI::addr($after), $text, -1);
    }

    public static function moveCursorToEnd(CData $buffer): void
    {
        $ffi = self::ffi();
        $end = $ffi->new('CyonimaTextIter');
        $ffi->gtk_text_buffer_get_end_iter($buffer, FFI::addr($end));
        $ffi->gtk_text_buffer_place_cursor($buffer, FFI::addr($end));
    }

    public static function moveCursorToLine(CData $buffer, int $line): void
    {
        $ffi = self::ffi();
        $iter = $ffi->new('CyonimaTextIter');
        $ffi->gtk_text_buffer_get_iter_at_line($buffer, FFI::addr($iter), max(0, $line));
        $ffi->gtk_text_buffer_place_cursor($buffer, FFI::addr($iter));
    }

    private static function insertIter(CData $buffer): CData
    {
        $ffi = self::ffi();
        $iter = $ffi->new('CyonimaTextIter');
        $mark = $ffi->gtk_text_buffer_get_insert($buffer);
        $ffi->gtk_text_buffer_get_iter_at_mark($buffer, FFI::addr($iter), $mark);

        return $iter;
    }

    private static function copy(CData $iter): CData
    {
        $copy = self::ffi()->new('CyonimaTextIter');
        FFI::memcpy(FFI::addr($copy), FFI::addr($iter), self::ITER_SIZE);

        return $copy;
    }

    private static function textBetween(CData $buffer, CData $start, CData $end): string
    {
        $ffi = self::ffi();
        $pointer = $ffi->gtk_text_buffer_get_text($buffer, FFI::addr($start), FFI::addr($end), 0);

        if ($pointer === null) {
            return '';
        }

        $text = FFI::string($pointer);
        $ffi->g_free($pointer);

        return $text;
    }

    private static function isWordChar(int $code): bool
    {
        return ($code >= 48 && $code <= 57)   // 0-9
            || ($code >= 65 && $code <= 90)   // A-Z
            || ($code >= 97 && $code <= 122)  // a-z
            || $code === 95                   // _
            || $code === 36                   // $
            || $code === 92;                  // backslash
    }
}
