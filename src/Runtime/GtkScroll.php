<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

use FFI;
use FFI\CData;

/**
 * Petites opérations de défilement sur un GtkScrolledWindow.
 *
 * Les bindings générés n'exposent pas GtkAdjustment ; ce helper garde l'accès
 * FFI dans le runtime, comme GtkText pour les buffers.
 */
final class GtkScroll
{
    private const CDEF = <<<'C'
        void  *gtk_scrolled_window_get_vadjustment(void *scrolled);
        double gtk_adjustment_get_upper(void *adjustment);
        double gtk_adjustment_get_page_size(void *adjustment);
        double gtk_adjustment_get_lower(void *adjustment);
        void   gtk_adjustment_set_value(void *adjustment, double value);
        C;

    private static ?FFI $ffi = null;

    public static function toBottom(CData $scrolledWindow): void
    {
        $ffi = self::ffi();
        $adjustment = $ffi->gtk_scrolled_window_get_vadjustment($scrolledWindow);

        if ($adjustment === null) {
            return;
        }

        $upper = (float) $ffi->gtk_adjustment_get_upper($adjustment);
        $page = (float) $ffi->gtk_adjustment_get_page_size($adjustment);
        $lower = (float) $ffi->gtk_adjustment_get_lower($adjustment);

        $ffi->gtk_adjustment_set_value($adjustment, max($lower, $upper - $page));
    }

    private static function ffi(): FFI
    {
        return self::$ffi ??= FFI::cdef(self::CDEF, 'libgtk-4.so.1');
    }
}
