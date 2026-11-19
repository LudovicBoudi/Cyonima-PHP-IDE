<?php

declare(strict_types=1);

namespace Cyonima\Gtk\Raw;

use FFI\CData;
use Throwable;

/**
 * Base de tous les objets GObject exposés au PHP.
 *
 * Deux règles non négociables y sont appliquées :
 *  - R1 : une closure n'est convertie en pointeur C qu'à la connexion d'un
 *    signal, jamais par événement. Les handlers sont gardés en vie ici.
 *  - R2 : aucune exception ne traverse la frontière C ; elle est capturée puis
 *    transmise au gestionnaire d'erreurs applicatif.
 *
 * PHP réserve le nom de classe `Object` : la classe GObject.Object des typelibs
 * n'est donc pas générée sous ce nom, et cette classe joue le rôle de racine.
 */
abstract class RawObject
{
    private static ?\Closure $errorHandler = null;
    private static int $nextSignalId = 0;

    /** @var array<int, \Closure> */
    private array $signalHandlers = [];

    public function __construct(
        protected CData $handle,
        private bool $owned = true,
    ) {
    }

    /**
     * Prend possession d'une référence flottante retournée par un constructeur
     * GObject : la référence devient normale et sera libérée au destructeur.
     */
    public static function adopt(CData $handle): CData
    {
        return Symbols::get()->g_object_ref_sink($handle);
    }

    /** Enveloppe un pointeur existant, sans en prendre possession par défaut. */
    public static function wrap(CData $handle, bool $owned = false): static
    {
        return new static($handle, $owned);
    }

    public function handle(): CData
    {
        return $this->handle;
    }

    public function isOwned(): bool
    {
        return $this->owned;
    }

    /**
     * Installe le gestionnaire d'erreurs des callbacks. Sans lui, les erreurs
     * partent sur STDERR et l'application continue.
     */
    public static function onError(?callable $handler): void
    {
        self::$errorHandler = $handler === null ? null : $handler(...);
    }

    /**
     * Connecte un handler PHP à un signal GObject.
     *
     * @param int $arity nombre de paramètres du signal (hors instance)
     */
    public function connectSignal(string $signal, callable $handler, int $arity = 0): int
    {
        $id = ++self::$nextSignalId;

        $adapter = function (
            mixed $instance,
            mixed $a1 = null,
            mixed $a2 = null,
            mixed $a3 = null,
            mixed $a4 = null,
        ) use ($handler, $arity, $signal): int {
            try {
                $arguments = [$this, $a1, $a2, $a3, $a4];
                $result = $handler(...array_slice($arguments, 0, 1 + $arity));

                if (is_bool($result)) {
                    return $result ? 1 : 0;
                }
                return is_int($result) ? $result : 0;
            } catch (Throwable $exception) {
                self::reportError($exception, $signal);
                return 0;
            }
        };

        $this->signalHandlers[$id] = $adapter;
        Symbols::get()->g_signal_connect_data($this->handle, $signal, $adapter, null, null, 0);

        return $id;
    }

    /** Libère la garde PHP du handler (le signal reste connecté côté GTK). */
    public function forgetSignal(int $id): void
    {
        unset($this->signalHandlers[$id]);
    }

    /**
     * Émet un signal depuis PHP. Utile pour les tests d'UI et les scénarios
     * scriptés ; le chemin normal reste l'interaction utilisateur.
     */
    public function emit(string $signal, mixed ...$arguments): void
    {
        Symbols::get()->g_signal_emit_by_name($this->handle, $signal, ...$arguments);
    }

    private static function reportError(Throwable $exception, string $signal): void
    {
        if (self::$errorHandler !== null) {
            (self::$errorHandler)($exception, $signal);
            return;
        }

        fwrite(STDERR, sprintf(
            "[cyonima] exception dans le handler '%s' : %s: %s\n",
            $signal,
            $exception::class,
            $exception->getMessage(),
        ));
    }

    public function __destruct()
    {
        // Les handlers doivent disparaître avant l'unref : la destruction peut
        // émettre des signaux et rappeler du code PHP.
        $this->signalHandlers = [];

        if ($this->owned) {
            Symbols::get()->g_object_unref($this->handle);
        }
    }
}
