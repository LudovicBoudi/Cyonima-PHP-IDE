<?php

declare(strict_types=1);

namespace Cyonima\Runtime;

/**
 * Accès Git local, sans dépendance externe.
 *
 * Les commandes sont lancées avec un tableau d'arguments (pas de shell), le
 * dépôt comme répertoire courant et `GIT_TERMINAL_PROMPT=0` pour éviter toute
 * invite interactive.
 */
final class GitRepository
{
    private static ?bool $available = null;

    private function __construct(private readonly string $root)
    {
    }

    public static function available(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }

        $paths = explode(PATH_SEPARATOR, getenv('PATH') ?: '');

        foreach ($paths as $directory) {
            if ($directory !== '' && is_file($directory . '/git') && is_executable($directory . '/git')) {
                return self::$available = true;
            }
        }

        return self::$available = false;
    }

    public static function discover(string $path): ?self
    {
        if (!self::available() || !is_dir($path)) {
            return null;
        }

        $result = self::execIn($path, ['rev-parse', '--show-toplevel']);

        if ($result['exit'] !== 0) {
            return null;
        }

        $root = trim($result['stdout']);

        return $root === '' ? null : new self($root);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function branch(): string
    {
        $result = $this->git(['rev-parse', '--abbrev-ref', 'HEAD']);
        $branch = trim($result['stdout']);

        if ($result['exit'] !== 0 || $branch === 'HEAD' || $branch === '') {
            $result = $this->git(['symbolic-ref', '--short', 'HEAD']);
            $branch = trim($result['stdout']);
        }

        return $branch === '' ? '—' : $branch;
    }

    /** @return list<string> branches locales */
    public function branches(): array
    {
        $result = $this->git(['for-each-ref', '--format=%(refname:short)', 'refs/heads']);

        if ($result['exit'] !== 0) {
            return [];
        }

        $lines = preg_split('/\R/', trim($result['stdout'])) ?: [];

        return array_values(array_filter(
            array_map('trim', $lines),
            static fn (string $branch): bool => $branch !== '',
        ));
    }

    /** @return array{ok: bool, output: string} */
    public function createBranch(string $name): array
    {
        $name = trim($name);

        if ($name === '') {
            return ['ok' => false, 'output' => 'Nom de branche vide'];
        }

        return $this->result(['checkout', '-b', $name]);
    }

    /** @return array{ok: bool, output: string} */
    public function switchBranch(string $name): array
    {
        $name = trim($name);

        if ($name === '') {
            return ['ok' => false, 'output' => 'Nom de branche vide'];
        }

        if (!in_array($name, $this->branches(), true)) {
            return ['ok' => false, 'output' => 'Branche inconnue : ' . $name];
        }

        return $this->result(['checkout', $name]);
    }

    /** @return array{ok: bool, output: string} */
    public function merge(string $branch): array
    {
        $branch = trim($branch);

        if ($branch === '') {
            return ['ok' => false, 'output' => 'Nom de branche vide'];
        }

        if (!in_array($branch, $this->branches(), true)) {
            return ['ok' => false, 'output' => 'Branche inconnue : ' . $branch];
        }

        return $this->result(['merge', '--no-edit', $branch]);
    }

    /** @return array{ok: bool, output: string} */
    private function result(array $arguments): array
    {
        $result = $this->git($arguments);
        $output = trim($result['stdout'] . "\n" . $result['stderr']);

        return ['ok' => $result['exit'] === 0, 'output' => $output];
    }

    /**
     * @return list<array{path: string, index: string, worktree: string, staged: bool}>
     */
    public function status(): array
    {
        $result = $this->git(['status', '--porcelain=v1', '-z', '--untracked-files=all']);

        if ($result['exit'] !== 0) {
            return [];
        }

        $tokens = explode("\0", $result['stdout']);
        $entries = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $line = $tokens[$index];

            if (strlen($line) < 4) {
                continue;
            }

            $state = $line[0];
            $worktree = $line[1];
            $path = substr($line, 3);

            if ($state === 'R' || $state === 'C' || $worktree === 'R' || $worktree === 'C') {
                $index++;
            }

            $entries[] = [
                'path' => $path,
                'index' => $state,
                'worktree' => $worktree,
                'staged' => $state !== ' ' && $state !== '?',
            ];
        }

        return $entries;
    }

    public function diff(?string $path = null, bool $staged = false): string
    {
        $arguments = ['diff', '--no-color'];

        if ($staged) {
            $arguments[] = '--cached';
        }

        if ($path !== null && $path !== '') {
            $arguments[] = '--';
            $arguments[] = $path;
        }

        return $this->git($arguments)['stdout'];
    }

    public function stagedDiff(): string
    {
        return $this->diff(null, true);
    }

    /** Diff d'un fichier : indexé puis non indexé, contenu complet si non suivi. */
    public function fileDiff(string $path): string
    {
        $parts = [];

        $staged = $this->diff($path, true);
        if (trim($staged) !== '') {
            $parts[] = $staged;
        }

        $unstaged = $this->diff($path);
        if (trim($unstaged) !== '') {
            $parts[] = $unstaged;
        }

        if ($parts === [] && $this->isUntracked($path)) {
            $result = $this->git([
                'diff',
                '--no-color',
                '--no-index',
                '--',
                '/dev/null',
                $this->root . '/' . $path,
            ]);

            if (trim($result['stdout']) !== '') {
                $parts[] = $result['stdout'];
            }
        }

        return implode("\n", $parts);
    }

    private function isUntracked(string $path): bool
    {
        foreach ($this->status() as $entry) {
            if ($entry['path'] === $path) {
                return $entry['index'] === '?';
            }
        }

        return false;
    }

    /** @param list<string> $paths */
    public function stage(array $paths): bool
    {
        if ($paths === []) {
            return false;
        }

        return $this->git(array_merge(['add', '--'], $paths))['exit'] === 0;
    }

    public function stageAll(): bool
    {
        return $this->git(['add', '-A'])['exit'] === 0;
    }

    /** @param list<string> $paths */
    public function unstage(array $paths): bool
    {
        if ($paths === []) {
            return false;
        }

        return $this->git(array_merge(['reset', '--'], $paths))['exit'] === 0;
    }

    /** @return array{ok: bool, output: string} */
    public function commit(string $message): array
    {
        $message = trim($message);

        if ($message === '') {
            return ['ok' => false, 'output' => 'Message de commit vide'];
        }

        $result = $this->git(['commit', '-m', $message]);
        $output = trim($result['stdout'] . "\n" . $result['stderr']);

        return ['ok' => $result['exit'] === 0, 'output' => $output];
    }

    /** @param list<string> $arguments @return array{exit: int, stdout: string, stderr: string} */
    private function git(array $arguments): array
    {
        return self::execIn($this->root, $arguments);
    }

    /** @param list<string> $arguments @return array{exit: int, stdout: string, stderr: string} */
    private static function execIn(string $cwd, array $arguments): array
    {
        if (!self::available()) {
            return ['exit' => 1, 'stdout' => '', 'stderr' => 'Git indisponible'];
        }

        $command = array_merge(['git', '-c', 'core.quotepath=false', '-C', $cwd], $arguments);
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment['GIT_TERMINAL_PROMPT'] = '0';

        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, $cwd, $environment);

        if (!is_resource($process)) {
            return ['exit' => 1, 'stdout' => '', 'stderr' => 'Git indisponible'];
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit' => proc_close($process),
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }
}
