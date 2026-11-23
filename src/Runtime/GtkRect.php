<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

use FFI;
use FFI\CData;

/** Petit utilitaire pour construire un GdkRectangle (16 octets). */
final class GtkRect
{
    private const CDEF = 'typedef struct { int x, y, width, height; } CyonimaRect;';

    private static ?FFI $ffi = null;

    public static function create(int $x, int $y, int $width, int $height): CData
    {
        self::$ffi ??= FFI::cdef(self::CDEF, 'libgtk-4.so.1');

        $rect = self::$ffi->new('CyonimaRect');
        $rect->x = $x;
        $rect->y = $y;
        $rect->width = $width;
        $rect->height = $height;

        return $rect;
    }
}
