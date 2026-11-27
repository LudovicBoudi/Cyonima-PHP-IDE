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
        double gtk_adjustment_get_value(void *adjustment);
        void   gtk_adjustment_set_value(void *adjustment, double value);
        unsigned long g_signal_connect_data(
            void *instance, const char *signal,
            void (*callback)(void*, void*), void *data, void *destroy, int flags);
        void   g_signal_handler_disconnect(void *instance, unsigned long handler_id);
        C;

    private static ?FFI $ffi = null;

    /** @var array<int, \Closure> garde les callbacks de signaux en vie */
    private static array $signalHandlers = [];

    public static function vadjustment(CData $scrolledWindow): ?CData
    {
        $adjustment = self::ffi()->gtk_scrolled_window_get_vadjustment($scrolledWindow);

        return $adjustment;
    }

    public static function value(CData $adjustment): float
    {
        return (float) self::ffi()->gtk_adjustment_get_value($adjustment);
    }

    public static function lower(CData $adjustment): float
    {
        return (float) self::ffi()->gtk_adjustment_get_lower($adjustment);
    }

    public static function upper(CData $adjustment): float
    {
        return (float) self::ffi()->gtk_adjustment_get_upper($adjustment);
    }

    public static function pageSize(CData $adjustment): float
    {
        return (float) self::ffi()->gtk_adjustment_get_page_size($adjustment);
    }

    public static function setValue(CData $adjustment, float $value): void
    {
        self::ffi()->gtk_adjustment_set_value($adjustment, $value);
    }

    public static function connectValueChanged(CData $adjustment, callable $handler): int
    {
        $adapter = static function (mixed $adjustmentPointer, mixed $data) use ($handler): void {
            $handler();
        };

        $id = (int) self::ffi()->g_signal_connect_data(
            $adjustment,
            'value-changed',
            $adapter,
            null,
            null,
            0,
        );
        self::$signalHandlers[$id] = $adapter;

        return $id;
    }

    public static function disconnect(CData $adjustment, int $handlerId): void
    {
        self::ffi()->g_signal_handler_disconnect($adjustment, $handlerId);
        unset(self::$signalHandlers[$handlerId]);
    }

    public static function syncFraction(CData $source, CData $target): void
    {
        $sourceSpan = self::upper($source) - self::pageSize($source) - self::lower($source);
        $targetSpan = self::upper($target) - self::pageSize($target) - self::lower($target);

        if ($sourceSpan <= 0.0 || $targetSpan <= 0.0) {
            return;
        }

        $fraction = (self::value($source) - self::lower($source)) / $sourceSpan;
        $fraction = max(0.0, min(1.0, $fraction));
        self::setValue($target, self::lower($target) + $fraction * $targetSpan);
    }

    public static function toBottom(CData $scrolledWindow): void
    {
        $adjustment = self::vadjustment($scrolledWindow);

        if ($adjustment === null) {
            return;
        }

        $upper = self::upper($adjustment);
        $page = self::pageSize($adjustment);
        $lower = self::lower($adjustment);

        self::setValue($adjustment, max($lower, $upper - $page));
    }

    private static function ffi(): FFI
    {
        return self::$ffi ??= FFI::cdef(self::CDEF, 'libgtk-4.so.1');
    }
}
