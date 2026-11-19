<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

use RuntimeException;
use Throwable;

/**
 * Sous-processus non bloquant.
 *
 * C'est la réponse aux tâches vraiment lourdes : un travail CPU qui n'appelle
 * jamais Async::sleep()/yield() figerait l'UI. Un processus séparé garde la
 * boucle GTK réactive et permet aussi de paralléliser.
 */
final class Process implements Awaitable
{
    private static int $nextId = 0;

    /** @var array<int, self> */
    private static array $active = [];

    private string $stdout = '';
    private string $stderr = '';
    private ?ProcessResult $result = null;

    /** @var list<callable> */
    private array $completions = [];

    private readonly float $startedAt;

    /**
     * @param list<string> $command
     * @param array<int, resource> $pipes
     */
    private function __construct(
        public readonly int $id,
        public readonly array $command,
        private mixed $handle,
        private array $pipes,
    ) {
        $this->startedAt = Loop::now();
    }

    /**
     * @param list<string> $command
     * @param array<string, string>|null $environment
     */
    public static function start(array $command, ?string $input = null, ?array $environment = null): self
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $handle = proc_open($command, $descriptors, $pipes, null, $environment);
        if (!is_resource($handle)) {
            throw new RuntimeException('Impossible de lancer : ' . implode(' ', $command));
        }

        foreach ([1, 2] as $descriptor) {
            stream_set_blocking($pipes[$descriptor], false);
        }

        stream_set_blocking($pipes[0], false);
        if ($input !== null && $input !== '') {
            fwrite($pipes[0], $input);
        }
        fclose($pipes[0]);

        $process = new self(++self::$nextId, $command, $handle, [
            1 => $pipes[1],
            2 => $pipes[2],
        ]);
        self::$active[$process->id] = $process;

        return $process;
    }

    /**
     * Lance du code PHP dans un processus fils (sans FFI, sans état partagé).
     *
     * @param list<string> $arguments
     */
    public static function php(string $code, array $arguments = []): self
    {
        return self::start([PHP_BINARY, '-d', 'ffi.enable=0', '-r', $code, ...$arguments]);
    }

    /**
     * @param list<string> $arguments
     */
    public static function phpScript(string $script, array $arguments = []): self
    {
        return self::start([PHP_BINARY, '-d', 'ffi.enable=0', $script, ...$arguments]);
    }

    /** À appeler à chaque tick de la boucle. */
    public static function poll(): void
    {
        foreach (self::$active as $id => $process) {
            $process->drain();

            $status = proc_get_status($process->handle);
            if ($status['running']) {
                continue;
            }

            $process->drain();
            foreach ($process->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process->handle);

            $process->finish((int) $status['exitcode']);
            unset(self::$active[$id]);
        }
    }

    public static function activeCount(): int
    {
        return count(self::$active);
    }

    /** Arrête tous les processus encore actifs (arrêt de l'application). */
    public static function terminateAll(): void
    {
        foreach (self::$active as $id => $process) {
            $process->stop();
            if (is_resource($process->handle)) {
                @proc_close($process->handle);
            }
            unset(self::$active[$id]);
        }
    }

    public function isComplete(): bool
    {
        return $this->result !== null;
    }

    public function onComplete(callable $callback): void
    {
        if ($this->result !== null) {
            $callback($this);
            return;
        }
        $this->completions[] = $callback;
    }

    public function result(): ProcessResult
    {
        if ($this->result === null) {
            throw new RuntimeException(sprintf('Le processus #%d tourne encore', $this->id));
        }
        return $this->result;
    }

    /** Attend la fin, depuis une tâche Async::run(). */
    public function await(): ProcessResult
    {
        /** @var ProcessResult $result */
        $result = Scheduler::await($this);
        return $result;
    }

    public function stop(): void
    {
        if (is_resource($this->handle)) {
            proc_terminate($this->handle);
        }
    }

    private function drain(): void
    {
        foreach ($this->pipes as $descriptor => $pipe) {
            if (!is_resource($pipe)) {
                continue;
            }
            while (($chunk = stream_get_contents($pipe)) !== false && $chunk !== '') {
                if ($descriptor === 1) {
                    $this->stdout .= $chunk;
                } else {
                    $this->stderr .= $chunk;
                }
            }
        }
    }

    private function finish(int $exitCode): void
    {
        $this->result = new ProcessResult(
            command: $this->command,
            exitCode: $exitCode,
            stdout: $this->stdout,
            stderr: $this->stderr,
            durationMs: Loop::now() - $this->startedAt,
        );

        $completions = $this->completions;
        $this->completions = [];

        foreach ($completions as $completion) {
            try {
                $completion($this);
            } catch (Throwable $exception) {
                Errors::report($exception, 'process completion');
            }
        }
    }
}
