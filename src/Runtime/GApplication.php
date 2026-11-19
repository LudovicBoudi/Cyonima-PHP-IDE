<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

use FFI;
use FFI\CData;
use RuntimeException;

/**
 * Pont minimal vers Gio.Application.
 *
 * Adw.ApplicationWindow exige que le GApplication ait émis `startup`, ce qui
 * suppose `g_application_register()`. On n'appelle volontairement jamais
 * `g_application_run()` : la boucle reste celle de `Loop`, donc les timers, les
 * fibres et les sous-processus fonctionnent de façon identique.
 *
 * Ce pont deviendra inutile quand le namespace Gio sera généré (M4).
 */
final class GApplication
{
    private const CDEF = <<<'C'
        typedef struct { unsigned int domain; int code; char *message; } GError;
        int  g_application_register(void *application, void *cancellable, void **error);
        void g_application_quit(void *application);
        C;

    private static ?FFI $ffi = null;

    private static function ffi(): FFI
    {
        return self::$ffi ??= FFI::cdef(self::CDEF, 'libgio-2.0.so.0');
    }

    /** Enregistre l'application et émet `startup`. */
    public static function register(CData $application): void
    {
        $error = self::ffi()->new('void*[1]', false);
        $error[0] = null;

        if (self::ffi()->g_application_register($application, null, $error) !== 0) {
            return;
        }

        $message = 'enregistrement de l\'application refusé par Gio';
        if ($error[0] !== null) {
            $gerror = self::ffi()->cast('GError*', $error[0]);
            $message = FFI::string($gerror->message);
        }

        throw new RuntimeException('GApplication : ' . $message);
    }

    public static function quit(CData $application): void
    {
        self::ffi()->g_application_quit($application);
    }
}
