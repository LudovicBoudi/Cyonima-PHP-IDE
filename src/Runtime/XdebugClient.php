<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

use RuntimeException;
use Throwable;

/**
 * Client de débogage Xdebug (protocole DBGp).
 *
 * L'IDE écoute sur un port TCP ; Xdebug s'y connecte au démarrage d'un script.
 * Les paquets sont des documents XML terminés par un octet NUL. Le transport
 * est non bloquant et branché sur le tick du runtime.
 *
 * Périmètre M5 : connexion, exécution, pas-à-pas, points d'arrêt, position
 * courante. L'inspection des variables et la pile seront ajoutées ensuite.
 */
final class XdebugClient
{
    public const DEFAULT_PORT = 9003;

    /** @var resource|null */
    private mixed $server = null;

    /** @var resource|null */
    private mixed $client = null;

    private string $incoming = '';
    private string $outgoing = '';

    private int $nextTransaction = 0;
    private bool $attached = false;
    private bool $listening = false;

    private bool $paused = false;
    private ?string $currentFile = null;
    private ?int $currentLine = null;
    private ?string $lastCommand = null;
    private ?string $ideKey = null;

    /** @var array<string, list<callable>> */
    private array $handlers = [];

    /**
     * @param int $port 0 pour un port éphémère (tests)
     */
    public function __construct(private readonly int $port = self::DEFAULT_PORT)
    {
    }

    public static function listen(int $port = self::DEFAULT_PORT): self
    {
        $client = new self($port);
        $client->open();
        return $client;
    }

    public function open(): void
    {
        if ($this->listening) {
            return;
        }

        $address = 'tcp://127.0.0.1:' . $this->port;
        $errno = 0;
        $error = '';

        $server = @stream_socket_server($address, $errno, $error);
        if ($server === false) {
            throw new RuntimeException(sprintf('Xdebug : écoute impossible sur %s (%s)', $address, $error));
        }

        stream_set_blocking($server, false);
        $this->server = $server;
        $this->listening = true;
    }

    public function port(): int
    {
        if ($this->server === null) {
            return $this->port;
        }

        $name = (string) stream_socket_get_name($this->server, false);
        $position = strrpos($name, ':');

        return $position === false ? $this->port : (int) substr($name, $position + 1);
    }

    /** Branche la lecture sur le tick du runtime. */
    public function attach(): void
    {
        if ($this->attached) {
            return;
        }
        $this->attached = true;
        Loop::onTick(function (): void {
            $this->poll();
        });
    }

    /** @param callable(mixed ...): void $handler */
    public function on(string $event, callable $handler): void
    {
        $this->handlers[$event][] = $handler;
    }

    public function isConnected(): bool
    {
        return $this->client !== null;
    }

    public function isPaused(): bool
    {
        return $this->paused;
    }

    public function currentFile(): ?string
    {
        return $this->currentFile;
    }

    public function currentLine(): ?int
    {
        return $this->currentLine;
    }

    /** Chemin local à partir d'un fileuri DBGp. */
    public static function localPath(string $fileUri): string
    {
        if (str_starts_with($fileUri, 'file://')) {
            $path = substr($fileUri, 7);
            return rawurldecode($path);
        }
        return $fileUri;
    }

    public function run(): void
    {
        $this->paused = false;
        $this->command('run');
    }

    public function stepInto(): void
    {
        $this->command('step_into');
    }

    public function stepOver(): void
    {
        $this->command('step_over');
    }

    public function stepOut(): void
    {
        $this->command('step_out');
    }

    public function stop(): void
    {
        $this->command('stop');
    }

    public function detach(): void
    {
        $this->command('detach');
    }

    public function setBreakpoint(string $fileUri, int $line): void
    {
        $this->command('breakpoint_set', [
            '-t' => 'line',
            '-f' => $fileUri,
            '-n' => $line,
        ]);
    }

    public function poll(): void
    {
        $this->accept();
        $this->flush();

        if ($this->client === null) {
            return;
        }

        while (($chunk = @fread($this->client, 65536)) !== false && $chunk !== '') {
            $this->incoming .= $chunk;
        }

        while (($position = strpos($this->incoming, "\0")) !== false) {
            $packet = substr($this->incoming, 0, $position);
            $this->incoming = substr($this->incoming, $position + 1);
            if (trim($packet) !== '') {
                $this->handlePacket($packet);
            }
        }

        if (feof($this->client)) {
            $this->closeClient();
        }
    }

    public function close(): void
    {
        $this->closeClient();

        if (is_resource($this->server)) {
            @fclose($this->server);
        }
        $this->server = null;
        $this->listening = false;
    }

    private function accept(): void
    {
        if ($this->client !== null || $this->server === null) {
            return;
        }

        $client = @stream_socket_accept($this->server, 0);
        if ($client === false) {
            return;
        }

        stream_set_blocking($client, false);
        $this->client = $client;
        $this->incoming = '';
        $this->outgoing = '';
        $this->emit('accept', []);
    }

    private function closeClient(): void
    {
        if (is_resource($this->client)) {
            @fclose($this->client);
        }

        $wasConnected = $this->client !== null;
        $this->client = null;
        $this->incoming = '';
        $this->outgoing = '';
        $this->paused = false;

        if ($wasConnected) {
            $this->emit('disconnect', []);
        }
    }

    private function handlePacket(string $packet): void
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($packet);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return;
        }

        $name = $xml->getName();

        if ($name === 'init') {
            $this->ideKey = isset($xml['idekey']) ? (string) $xml['idekey'] : null;
            $this->currentFile = isset($xml['fileuri']) ? (string) $xml['fileuri'] : null;
            $this->emit('connect', [
                'fileUri' => $this->currentFile,
                'ideKey' => $this->ideKey,
                'appId' => isset($xml['appid']) ? (string) $xml['appid'] : null,
            ]);
            $this->run();
            return;
        }

        if ($name === 'response') {
            $this->handleResponse($xml);
            return;
        }

        if ($name === 'notify') {
            $this->emit('notify', [(string) $xml['name']]);
        }
    }

    private function handleResponse(\SimpleXMLElement $xml): void
    {
        $status = isset($xml['status']) ? (string) $xml['status'] : '';
        $command = isset($xml['command']) ? (string) $xml['command'] : '';

        if ($status === 'break') {
            $message = $this->findMessage($xml);

            if ($message !== null) {
                $this->currentFile = $message['filename'];
                $this->currentLine = $message['lineno'];
            }

            $this->paused = true;
            $this->emit('break', [
                'fileUri' => $this->currentFile,
                'line' => $this->currentLine,
                'command' => $command,
            ]);
            return;
        }

        if ($status === 'running') {
            $this->paused = false;
            $this->emit('running', []);
            return;
        }

        if ($status === 'stopped') {
            $this->paused = false;
            $this->emit('stop', []);
            return;
        }

        if ($command !== '') {
            $this->emit('response', [$command, $status, $xml]);
        }
    }

    /**
     * @return null|array{filename: ?string, lineno: ?int}
     */
    private function findMessage(\SimpleXMLElement $xml): ?array
    {
        $candidates = [];
        foreach ($xml->children() as $child) {
            $candidates[] = $child;
        }
        foreach ($xml->getNamespaces(true) as $namespace) {
            foreach ($xml->children($namespace) as $child) {
                $candidates[] = $child;
            }
        }

        foreach ($candidates as $child) {
            if ($child->getName() !== 'message') {
                continue;
            }

            // Sur un élément d'un namespace non par défaut, l'accès tableau
            // ($child['filename']) ne résout pas les attributs : attributes().
            $attributes = $child->attributes();

            return [
                'filename' => isset($attributes['filename']) ? (string) $attributes['filename'] : null,
                'lineno' => isset($attributes['lineno']) ? (int) $attributes['lineno'] : null,
            ];
        }

        return null;
    }

    private function command(string $name, array $arguments = []): void
    {
        if ($this->client === null) {
            return;
        }

        $attributes = ['-i' => ++$this->nextTransaction];

        if ($name === 'run' || str_starts_with($name, 'step_')) {
            $attributes = [];
        }

        foreach ($arguments as $key => $value) {
            $attributes[$key] = $value;
        }

        $xml = '<' . $name;
        foreach ($attributes as $key => $value) {
            $xml .= sprintf(
                ' %s="%s"',
                $key,
                htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1),
            );
        }
        $xml .= '/>';

        $this->outgoing .= $xml . "\0";
        $this->lastCommand = $name;
        $this->flush();
    }

    private function flush(): void
    {
        if ($this->outgoing === '' || $this->client === null) {
            return;
        }

        $written = @fwrite($this->client, $this->outgoing);
        if ($written === false || $written === 0) {
            return;
        }

        $this->outgoing = substr($this->outgoing, $written);
    }

    /** @param list<mixed> $arguments */
    private function emit(string $event, array $arguments): void
    {
        foreach ($this->handlers[$event] ?? [] as $handler) {
            try {
                // Tableau associatif → arguments positionnels (PHP 8 passerait
                // sinon des arguments nommés, incompatibles avec les closures).
                $handler(...array_values($arguments));
            } catch (Throwable $exception) {
                Errors::report($exception, 'xdebug ' . $event);
            }
        }
    }
}
