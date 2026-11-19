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
    private static ?\Closure $signalDispatcher = null;
    private static int $nextSignalId = 0;

    /** @var array<string, \FFI> handles FFI par signature de callback */
    private static array $signalHandles = [];

    /** @var array<int, \Closure> */
    private array $signalHandlers = [];

    /** @var array<int, int> identifiant interne => handler GLib */
    private array $glibHandlerIds = [];

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
     * Installe le dispatcher des handlers de signaux asynchrones.
     *
     * Signature attendue : function (callable $handler, array $arguments, string $signal): void
     * Sans dispatcher, les handlers de signaux void restent synchrones.
     */
    public static function dispatchSignalsThrough(?callable $dispatcher): void
    {
        self::$signalDispatcher = $dispatcher === null ? null : $dispatcher(...);
    }

    /**
     * Connecte un handler PHP à un signal GObject.
     *
     * @param list<string> $parameterTypes types C des paramètres du signal
     *        (hors instance), par exemple ['int'] pour child-exited.
     * @param bool $async exécute le handler dans une fibre si un dispatcher
     *        est installé (les signaux à retour doivent rester synchrones).
     */
    public function connectSignal(
        string $signal,
        callable $handler,
        array $parameterTypes = [],
        bool $async = true,
    ): int {
        $id = ++self::$nextSignalId;

        $adapter = function (mixed $instance, mixed ...$arguments) use ($handler, $signal, $async): int {
            $callArguments = [$this, ...$arguments];

            if ($async && self::$signalDispatcher !== null) {
                try {
                    (self::$signalDispatcher)($handler, $callArguments, $signal);
                    return 0;
                } catch (\FiberError) {
                    // Pendant une destruction, aucune fibre ne peut démarrer :
                    // on exécute le handler de façon synchrone.
                } catch (Throwable $exception) {
                    self::reportError($exception, $signal);
                    return 0;
                }
            }

            try {
                $result = $handler(...$callArguments);

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
        $glibId = self::signalHandle($parameterTypes)->g_signal_connect_data(
            $this->handle,
            $signal,
            $adapter,
            null,
            null,
            0,
        );
        $this->glibHandlerIds[$id] = (int) $glibId;

        return $id;
    }

    /**
     * Un cdef par signature de callback : libffi doit connaître le type exact
     * des paramètres, sans quoi un `gint` arrive en `void*` (CData).
     *
     * @param list<string> $parameterTypes
     */
    private static function signalHandle(array $parameterTypes): \FFI
    {
        $key = implode(',', $parameterTypes);

        if (!isset(self::$signalHandles[$key])) {
            $parameters = $parameterTypes === []
                ? 'void*'
                : 'void*, ' . implode(', ', $parameterTypes);

            self::$signalHandles[$key] = \FFI::cdef(
                sprintf(
                    'unsigned long g_signal_connect_data(void*, const char*, int (*)(%s), void*, void*, int);',
                    $parameters,
                ),
                'libgtk-4.so.1',
            );
        }

        return self::$signalHandles[$key];
    }

    /** Déconnecte le signal et libère la garde PHP du handler. */
    public function forgetSignal(int $id): void
    {
        if (isset($this->glibHandlerIds[$id])) {
            Symbols::get()->g_signal_handler_disconnect($this->handle, $this->glibHandlerIds[$id]);
            unset($this->glibHandlerIds[$id]);
        }
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
        // Un wrapper peut mourir alors que l'objet GObject survit (enfant d'un
        // conteneur) : sans déconnexion, la trampoline C pointerait vers une
        // closure PHP libérée.
        foreach ($this->glibHandlerIds as $glibId) {
            Symbols::get()->g_signal_handler_disconnect($this->handle, $glibId);
        }
        $this->glibHandlerIds = [];
        $this->signalHandlers = [];

        if ($this->owned) {
            Symbols::get()->g_object_unref($this->handle);
        }
    }
}
