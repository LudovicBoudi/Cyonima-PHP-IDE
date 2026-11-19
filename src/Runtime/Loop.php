<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

use FFI;
use FFI\CData;

/**
 * Boucle d'événements GLib.
 *
 * Infrastructure du runtime, pas des bindings générés : GLib n'est pas un
 * toolkit orienté objet et ses fonctions (timeout, idle) prennent des callbacks,
 * ce qui sort du périmètre du générateur pour la tranche M1.
 *
 * Règle R1 : `after()` convertit une closure en pointeur C (~450 octets non
 * récupérés). Utilisation ponctuelle uniquement, jamais dans une boucle.
 */
final class Loop
{
    private const CDEF = <<<'C'
typedef int (*CyonimaSourceFunc)(void*);
void         *g_main_loop_new(void *context, int is_running);
void          g_main_loop_run(void *loop);
void          g_main_loop_quit(void *loop);
unsigned int  g_timeout_add(unsigned int interval, CyonimaSourceFunc function, void *data);
int           g_source_remove(unsigned int tag);
C;

    private static ?FFI $ffi = null;
    private static ?CData $loop = null;
    private static int $nextId = 0;

    /** @var array<int, \Closure> */
    private static array $callbacks = [];

    /** @var list<\Closure> */
    private static array $onQuit = [];

    private static function ffi(): FFI
    {
        return self::$ffi ??= FFI::cdef(self::CDEF, 'libglib-2.0.so.0');
    }

    /**
     * Lance la boucle et bloque jusqu'à quit().
     *
     * @param null|callable(): void $onQuit exécuté après la sortie de boucle
     */
    public static function run(?callable $onQuit = null): void
    {
        if ($onQuit !== null) {
            self::$onQuit[] = $onQuit(...);
        }

        self::$loop = self::ffi()->g_main_loop_new(null, 0);
        self::ffi()->g_main_loop_run(self::$loop);
        self::$loop = null;

        foreach (self::$onQuit as $callback) {
            $callback();
        }
        self::$onQuit = [];
    }

    public static function quit(): void
    {
        if (self::$loop !== null) {
            self::ffi()->g_main_loop_quit(self::$loop);
        }
    }

    /** Exécute un callback après un délai, une seule fois. */
    public static function after(int $milliseconds, callable $callback): int
    {
        $id = ++self::$nextId;

        $adapter = function (mixed $data) use ($callback, $id): int {
            unset(self::$callbacks[$id]);
            $callback();
            return 0; // G_SOURCE_REMOVE
        };

        self::$callbacks[$id] = $adapter;
        return self::ffi()->g_timeout_add($milliseconds, $adapter, null);
    }

    public static function cancel(int $tag): void
    {
        self::ffi()->g_source_remove($tag);
        unset(self::$callbacks[$tag]);
    }
}
