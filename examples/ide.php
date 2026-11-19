<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Cyonima\Gtk\Raw\Orientation;
use Cyonima\Runtime\AdwApplication;
use Cyonima\Runtime\Loop;
use Cyonima\Runtime\LspClient;
use Cyonima\Runtime\XdebugClient;
use Cyonima\UI\Button;
use Cyonima\UI\CodeEditor;
use Cyonima\UI\CompletionPopup;
use Cyonima\UI\FileBrowser;
use Cyonima\UI\Paned;
use Cyonima\UI\ScrolledWindow;
use Cyonima\UI\Terminal;

/**
 * Mini IDE : navigateur de fichiers, éditeur GtkSourceView avec ouverture et
 * enregistrement réels, terminal VTE, exécution du fichier courant.
 */

$auto = in_array('--auto', $argv, true);
$root = dirname(__DIR__) . '/examples';

$app = new AdwApplication('com.cyonima.ide-demo');

$app->addCss(<<<'CSS'
.sidebar { background: #161a20; }
.browser-path { color: #7d8794; font-size: 11px; padding: 4px 2px; }
CSS);

$window = $app->window('Cyonima', 'IDE natif · GtkSourceView · VTE');

$editor = (new CodeEditor('php'))->scheme('Adwaita');
$terminal = new Terminal(5000);

$spawnPid = null;
$currentFile = null;
$dirty = false;
$loading = false;

$lsp = null;
$lspReady = false;
$diagnosticCount = 0;
$completionItems = 0;

$popup = new CompletionPopup($editor);

$terminal
    ->spawn(['/bin/bash'], $root, function (int $pid, ?string $error) use (&$spawnPid): void {
        $spawnPid = $pid;
        if ($error !== null) {
            fwrite(STDERR, "spawn VTE : {$error}\n");
        }
    })
    ->onChildExited(function (Terminal $term, int $status): void {
        // Le shell interactif ne sort pas pendant la démo.
    });

$openFile = function (string $path) use (
    $editor,
    $window,
    &$currentFile,
    &$dirty,
    &$loading,
    &$lsp,
    &$lspReady,
): void {
    if (!is_file($path) || !is_readable($path)) {
        $window->toast('Fichier illisible');
        return;
    }

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $language = match ($extension) {
        'php' => 'php',
        'md' => 'markdown',
        'json' => 'json',
        'sh' => 'sh',
        default => 'php',
    };

    $loading = true;
    $editor->language($language);
    $editor->setText((string) file_get_contents($path));
    $editor->moveCursorToEnd();
    $loading = false;

    $currentFile = $path;
    $dirty = false;
    $window->subtitle(basename($path));

    if ($lsp !== null && $lspReady) {
        $lsp->didOpen('file://' . $path, $language, $editor->text());
    }
};

$editor->onChanged(function () use (&$dirty, &$loading, &$currentFile, $window): void {
    if ($loading || $currentFile === null) {
        return;
    }
    $dirty = true;
    $window->subtitle(basename($currentFile) . ' •');
});

$save = function () use ($editor, $window, &$currentFile, &$dirty): bool {
    if ($currentFile === null) {
        $window->toast('Aucun fichier ouvert');
        return false;
    }

    file_put_contents($currentFile, $editor->text());
    $dirty = false;
    $window->subtitle(basename($currentFile));
    $window->toast('Enregistré : ' . basename($currentFile));

    return true;
};

$saveButton = (new Button('Enregistrer'))->onClicked(fn () => $save());

$run = (new Button('Exécuter'))->onClicked(function () use (
    $editor,
    $terminal,
    $window,
    $save,
    &$currentFile,
): void {
    if ($currentFile !== null && str_ends_with($currentFile, '.php')) {
        $save();
        $terminal->write('php ' . escapeshellarg($currentFile) . "\n");
        return;
    }

    $temp = tempnam(sys_get_temp_dir(), 'cyonima_');
    file_put_contents($temp, $editor->text());
    $terminal->write('php ' . escapeshellarg($temp) . "\n");
    $window->toast('Script temporaire exécuté');
});

// Serveur LSP optionnel : CYONIMA_LSP="phpactor language-server"
$lspCommand = getenv('CYONIMA_LSP');
if ($lspCommand !== false && trim($lspCommand) !== '') {
    $lsp = LspClient::start(preg_split('/\s+/', trim($lspCommand)) ?: []);
    $lsp->attach();
    $lsp->on('textDocument/publishDiagnostics', function (array $params) use ($window, &$diagnosticCount): void {
        $count = count($params['diagnostics'] ?? []);
        $diagnosticCount += $count;
        $window->toast("LSP : {$count} diagnostic(s)");
    });
    $lsp->initialize('file://' . $root, function () use ($window, &$lspReady): void {
        $lspReady = true;
        $window->toast('LSP prêt');
    });
}

$complete = function () use (
    $editor,
    $lsp,
    $popup,
    $window,
    &$lspReady,
    &$currentFile,
    &$completionItems,
): void {
    if ($lsp === null || !$lspReady || $currentFile === null) {
        $window->toast('Complétion : LSP indisponible');
        return;
    }

    $cursor = $editor->cursor();
    $prefix = $editor->wordPrefix();

    $lsp->request('textDocument/completion', [
        'textDocument' => ['uri' => 'file://' . $currentFile],
        'position' => ['line' => $cursor['line'], 'character' => $cursor['character']],
    ], function (mixed $result) use ($editor, $popup, $prefix, &$completionItems): void {
        $items = LspClient::normalizeCompletion($result);
        $completionItems = count($items);
        if ($items === []) {
            return;
        }

        $popup->show($items, function (array $item) use ($editor, $prefix): void {
            $length = function_exists('mb_strlen')
                ? mb_strlen($prefix, 'UTF-8')
                : strlen($prefix);
            $editor->replacePrefix($length, $item['insertText'] ?? $item['label']);
        });
    });
};

// Ctrl+Espace déclenche la complétion ; le popover capte ensuite les flèches,
// Entrée et Échap.
$editor->onKeyPressed(function (CodeEditor $editor, int $keyval, int $state) use ($popup, $complete): bool {
    $control = ($state & 4) !== 0; // GDK_CONTROL_MASK

    if ($control && $keyval === 0x20) { // Ctrl+Espace
        $complete();
        return true;
    }

    if (!$popup->visible()) {
        return false;
    }

    return match ($keyval) {
        0xff1b => $popup->hide() ?? true,          // Échap
        0xff0d, 0xff8d => $popup->chooseCurrent() ?? true, // Entrée
        0xff52 => $popup->move(-1) ?? true,        // Haut
        0xff54 => $popup->move(1) ?? true,         // Bas
        default => false,
    };
});

$debugPort = (int) (getenv('CYONIMA_XDEBUG_PORT') ?: XdebugClient::DEFAULT_PORT);
$debug = null;

$debugButton = (new Button('Déboguer'))->onClicked(function () use (
    $debugPort,
    $window,
    $editor,
    $openFile,
    &$debug,
    &$currentFile,
): void {
    if ($debug !== null && $debug->isConnected()) {
        $debug->run();
        $window->toast('Reprise de l\'exécution');
        return;
    }

    if ($debug === null) {
        $debug = XdebugClient::listen($debugPort);
        $debug->attach();

        $debug->on('connect', function () use ($debug, $window, $editor, &$currentFile): void {
            if ($currentFile !== null) {
                $debug->setBreakpoint('file://' . $currentFile, $editor->cursor()['line'] + 1);
            }
            $window->toast('Xdebug connecté');
        });

        $debug->on('break', function (?string $fileUri, ?int $line) use (
            $window,
            $editor,
            $openFile,
            &$currentFile,
        ): void {
            if ($fileUri === null || $line === null) {
                return;
            }

            $path = XdebugClient::localPath($fileUri);
            if ($path !== $currentFile && is_file($path)) {
                $openFile($path);
            }

            $editor->moveCursorToLine(max(0, $line - 1));
            $window->subtitle(basename($path) . ' : ligne ' . $line);
            $window->toast('Pause en ' . basename($path) . ':' . $line);
        });

        $debug->on('stop', function () use ($window): void {
            $window->toast('Session de débogage terminée');
        });
    }

    $window->toast('Xdebug en attente sur 127.0.0.1:' . $debugPort);
});

$browser = new FileBrowser($root, $openFile);

$sidebar = (new ScrolledWindow($browser))->cssClass('sidebar');
$sidebar->raw()->setSizeRequest(230, -1);

$editorArea = (new ScrolledWindow($editor))->expand();
$mainArea = (new Paned(Orientation::VERTICAL, 420))
    ->start($editorArea)
    ->end($terminal);

$layout = (new Paned(Orientation::HORIZONTAL, 230))
    ->start($sidebar)
    ->end($mainArea);

$window
    ->headerEnd($saveButton, $debugButton, $run)
    ->content($layout)
    ->present();

$sample = null;

if ($auto) {
    // On travaille sur une copie : le scénario automatique sauvegarde.
    $sample = sys_get_temp_dir() . '/cyonima_ide_demo.php';
    copy($root . '/hello.php', $sample);
    Loop::after(300, fn () => $openFile($sample));
    Loop::after(500, fn () => $run->click());
    Loop::after(800, fn () => $complete());
    Loop::after(1100, fn () => $popup->chooseCurrent());
    Loop::after(1500, fn () => $save());
    Loop::after(1900, fn () => $window->raw()->close());
}

$app->run();

$lsp?->stop();
$debug?->close();

if ($sample !== null && is_file($sample)) {
    @unlink($sample);
}

printf(
    "Fichier : %s | lignes : %d | caractères : %d | modifié : %s | terminal : %s | diagnostics : %d | propositions : %d\n",
    $currentFile ?? 'aucun',
    $editor->lineCount(),
    $editor->charCount(),
    $dirty ? 'oui' : 'non',
    $spawnPid ?? 'aucun',
    $diagnosticCount,
    $completionItems,
);
