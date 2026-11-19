<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

use Cyonima\Gtk\Raw\RawObject;

/**
 * Démarrage unique du runtime, partagé par les applications GTK et Adw.
 *
 * Branche ensemble : erreurs, dispatcher de signaux, scheduler de fibres et
 * surveillance des sous-processus.
 */
final class Kernel
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        RawObject::onError(static function (\Throwable $error, string $signal): void {
            Errors::report($error, 'signal ' . $signal);
        });

        // Les handlers de signaux void deviennent des tâches coopératives.
        RawObject::dispatchSignalsThrough(static function (callable $handler, array $arguments, string $signal): void {
            Scheduler::spawn(static function () use ($handler, $arguments, $signal): void {
                try {
                    $handler(...$arguments);
                } catch (\Throwable $error) {
                    Errors::report($error, 'signal ' . $signal);
                }
            });
        });

        Loop::onTick(static fn (): int => Scheduler::tick());
        Loop::onTick(static function (): void {
            Process::poll();
        });
    }

    public static function reset(): void
    {
        self::$booted = false;
    }
}
