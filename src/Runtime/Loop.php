<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

use FFI;
use FFI\CData;

/**
 * Boucle d'événements GLib et ordonnanceur de timers.
 *
 * Règle R1 : une seule source GLib est enregistrée, au démarrage. Les timers
 * applicatifs vivent dans une file PHP et sont déclenchés par ce tick unique.
 * Enregistrer un `g_timeout_add` par événement fuiterait ~450 octets à chaque
 * appel (mesuré), soit des dizaines de Mo par heure pour une UI active.
 */
final class Loop
{
    private const TICK_MS = 16;

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
    private static bool $running = false;
    private static ?\Closure $tickAdapter = null;

    /** @var array<int, array{at: float, callback: callable}> */
    private static array $timers = [];

    /** @var list<callable> */
    private static array $tickListeners = [];

    /** @var list<\Closure> */
    private static array $onQuit = [];

    private static int $nextTimerId = 0;

    private static function ffi(): FFI
    {
        return self::$ffi ??= FFI::cdef(self::CDEF, 'libglib-2.0.so.0');
    }

    public static function now(): float
    {
        return hrtime(true) / 1e6;
    }

    /** Abonnement appelé à chaque tick (scheduler, processus, animations…). */
    public static function onTick(callable $listener): void
    {
        self::$tickListeners[] = $listener;
    }

    /** Exécute un callback après un délai, une seule fois. Aucune allocation C. */
    public static function after(int $milliseconds, callable $callback): int
    {
        $id = ++self::$nextTimerId;
        self::$timers[$id] = [
            'at' => self::now() + max(0, $milliseconds),
            'callback' => $callback,
        ];
        return $id;
    }

    public static function cancelTimer(int $id): void
    {
        unset(self::$timers[$id]);
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

        self::$running = true;
        self::$loop = self::ffi()->g_main_loop_new(null, 0);

        self::ensureTickSource();
        self::ffi()->g_main_loop_run(self::$loop);

        self::$loop = null;
        self::$running = false;
        self::$timers = [];

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

    public static function isRunning(): bool
    {
        return self::$running;
    }

    /** Réinitialise l'état runtime (tests, redémarrage logique). */
    public static function reset(): void
    {
        self::$timers = [];
        self::$tickListeners = [];
        self::$onQuit = [];
    }

    /**
     * Source GLib unique. Elle se retire d'elle-même quand la boucle s'arrête.
     */
    private static function ensureTickSource(): void
    {
        if (self::$tickAdapter === null) {
            self::$tickAdapter = static function (mixed $data): int {
                self::tick();
                return self::$running ? 1 : 0; // G_SOURCE_CONTINUE / G_SOURCE_REMOVE
            };
        }

        self::ffi()->g_timeout_add(self::TICK_MS, self::$tickAdapter, null);
    }

    private static function tick(): void
    {
        $now = self::now();

        foreach (self::$timers as $id => $timer) {
            if ($timer['at'] > $now) {
                continue;
            }
            unset(self::$timers[$id]);
            try {
                ($timer['callback'])();
            } catch (\Throwable $exception) {
                Errors::report($exception, 'timer');
            }
        }

        foreach (self::$tickListeners as $listener) {
            try {
                $listener($now);
            } catch (\Throwable $exception) {
                Errors::report($exception, 'tick');
            }
        }
    }
}
