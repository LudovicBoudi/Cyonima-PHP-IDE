<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

use RuntimeException;
use Throwable;

/**
 * Client LSP minimal (JSON-RPC 2.0 sur stdio).
 *
 * Couvre le cycle utile à l'IDE : initialize/initialized, didOpen, requêtes
 * avec réponse asynchrone, notifications serveur (diagnostics…). Le serveur
 * tourne dans un sous-processus et le transport est non bloquant : la boucle
 * GTK reste réactive.
 */
final class LspClient
{
    private const SEPARATOR = "\r\n\r\n";

    /** @var resource */
    private mixed $process;

    /** @var array<int, resource> */
    private array $pipes;

    private string $incoming = '';
    private string $outgoing = '';
    private string $stderr = '';

    private int $nextId = 0;
    private bool $attached = false;
    private bool $closed = false;

    /** @var array<int, callable> */
    private array $pending = [];

    /** @var array<string, list<callable>> */
    private array $handlers = [];

    /**
     * @param list<string> $command
     */
    private function __construct(mixed $process, array $pipes)
    {
        $this->process = $process;
        $this->pipes = $pipes;
    }

    /**
     * @param list<string> $command
     */
    public static function start(array $command, ?string $cwd = null): self
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            throw new RuntimeException('LSP : impossible de lancer ' . implode(' ', $command));
        }

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        return new self($process, $pipes);
    }

    /** Branche la lecture sur le tick du runtime. */
    public function attach(): void
    {
        if ($this->attached) {
            return;
        }
        $this->attached = true;
        Loop::onTick(fn () => $this->poll());
    }

    public function on(string $method, callable $handler): void
    {
        $this->handlers[$method][] = $handler;
    }

    /** @param callable(mixed $result): void $onReady */
    public function initialize(string $rootUri, callable $onReady): int
    {
        return $this->request('initialize', [
            'processId' => getmypid(),
            'rootUri' => $rootUri,
            'capabilities' => (object) [],
        ], function (mixed $result) use ($onReady): void {
            $this->notify('initialized', (object) []);
            $onReady($result);
        });
    }

    public function didOpen(string $uri, string $languageId, string $text, int $version = 1): void
    {
        $this->notify('textDocument/didOpen', [
            'textDocument' => [
                'uri' => $uri,
                'languageId' => $languageId,
                'version' => $version,
                'text' => $text,
            ],
        ]);
    }

    /**
     * @param array<string, mixed>|object $params
     * @param null|callable(mixed $result): void $onResult
     */
    public function request(string $method, array|object $params, ?callable $onResult = null): int
    {
        $id = ++$this->nextId;

        if ($onResult !== null) {
            $this->pending[$id] = $onResult;
        }

        $this->send([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ]);

        return $id;
    }

    /** @param array<string, mixed>|object $params */
    public function notify(string $method, array|object $params): void
    {
        $this->send([
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
        ]);
    }

    public function poll(): void
    {
        if ($this->closed) {
            return;
        }

        $this->flush();
        $this->read();
        $this->drainStderr();
    }

    public function stop(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        $this->pipes = [];

        if (is_resource($this->process)) {
            @proc_terminate($this->process);
            @proc_close($this->process);
        }
    }

    /**
     * Normalise une réponse `textDocument/completion` (liste ou CompletionList).
     *
     * @return list<array{label: string, insertText: ?string, detail: string, kind: int}>
     */
    public static function normalizeCompletion(mixed $result): array
    {
        if (!is_array($result)) {
            return [];
        }

        $items = $result['items'] ?? $result;
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['label'])) {
                continue;
            }

            $normalized[] = [
                'label' => (string) $item['label'],
                'insertText' => isset($item['insertText']) && is_string($item['insertText'])
                    ? $item['insertText']
                    : null,
                'detail' => isset($item['detail']) && is_string($item['detail']) ? $item['detail'] : '',
                'kind' => (int) ($item['kind'] ?? 0),
            ];
        }

        return $normalized;
    }

    /** @return list<string> dernières lignes de stderr du serveur */
    public function stderr(): array
    {
        return array_values(array_filter(explode("\n", $this->stderr)));
    }

    private function send(array $message): void
    {
        $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('LSP : encodage JSON impossible');
        }

        $this->outgoing .= 'Content-Length: ' . strlen($json) . self::SEPARATOR . $json;
        $this->flush();
    }

    private function flush(): void
    {
        if ($this->outgoing === '' || !isset($this->pipes[0]) || !is_resource($this->pipes[0])) {
            return;
        }

        $written = @fwrite($this->pipes[0], $this->outgoing);
        if ($written === false || $written === 0) {
            return;
        }

        $this->outgoing = substr($this->outgoing, $written);
    }

    private function read(): void
    {
        if (!isset($this->pipes[1]) || !is_resource($this->pipes[1])) {
            return;
        }

        while (($chunk = @fread($this->pipes[1], 65536)) !== false && $chunk !== '') {
            $this->incoming .= $chunk;
        }

        while (($position = strpos($this->incoming, self::SEPARATOR)) !== false) {
            $header = substr($this->incoming, 0, $position);

            if (!preg_match('/Content-Length:\s*(\d+)/i', $header, $matches)) {
                $this->incoming = substr($this->incoming, $position + strlen(self::SEPARATOR));
                continue;
            }

            $length = (int) $matches[1];
            $bodyStart = $position + strlen(self::SEPARATOR);

            if (strlen($this->incoming) < $bodyStart + $length) {
                return; // message incomplet
            }

            $body = substr($this->incoming, $bodyStart, $length);
            $this->incoming = substr($this->incoming, $bodyStart + $length);

            $message = json_decode($body, true);
            if (is_array($message)) {
                $this->dispatch($message);
            }
        }
    }

    private function drainStderr(): void
    {
        if (!isset($this->pipes[2]) || !is_resource($this->pipes[2])) {
            return;
        }

        while (($chunk = @fread($this->pipes[2], 8192)) !== false && $chunk !== '') {
            $this->stderr .= $chunk;
            if (strlen($this->stderr) > 8192) {
                $this->stderr = substr($this->stderr, -4096);
            }
        }
    }

    private function dispatch(array $message): void
    {
        // Réponse à une requête sortante.
        if (isset($message['id']) && (array_key_exists('result', $message) || array_key_exists('error', $message))) {
            $id = (int) $message['id'];
            $callback = $this->pending[$id] ?? null;
            unset($this->pending[$id]);

            if ($callback === null) {
                return;
            }

            try {
                if (array_key_exists('error', $message)) {
                    $callback(null);
                } else {
                    $callback($message['result']);
                }
            } catch (Throwable $exception) {
                Errors::report($exception, 'lsp response');
            }

            return;
        }

        // Requête du serveur vers le client : réponse nulle suffit ici.
        if (isset($message['id'], $message['method'])) {
            $this->send([
                'jsonrpc' => '2.0',
                'id' => $message['id'],
                'result' => null,
            ]);
            return;
        }

        // Notification serveur.
        if (isset($message['method'])) {
            foreach ($this->handlers[$message['method']] ?? [] as $handler) {
                try {
                    $handler($message['params'] ?? []);
                } catch (Throwable $exception) {
                    Errors::report($exception, 'lsp notification');
                }
            }
        }
    }
}
