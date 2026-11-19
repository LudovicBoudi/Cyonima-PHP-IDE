<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Cyonima\Gtk\Raw\Orientation;
use Cyonima\Runtime\AdwApplication;
use Cyonima\Runtime\Loop;
use Cyonima\Runtime\LspClient;
use Cyonima\UI\Button;
use Cyonima\UI\CodeEditor;
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
    ->headerEnd($saveButton, $run)
    ->content($layout)
    ->present();

if ($auto) {
    $sample = $root . '/hello.php';
    Loop::after(300, fn () => $openFile($sample));
    Loop::after(500, fn () => $run->click());
    Loop::after(1500, fn () => $save());
    Loop::after(1900, fn () => $window->raw()->close());
}

$app->run();

$lsp?->stop();

printf(
    "Fichier : %s | lignes : %d | caractères : %d | modifié : %s | pid terminal : %s | diagnostics LSP : %d\n",
    $currentFile ?? 'aucun',
    $editor->lineCount(),
    $editor->charCount(),
    $dirty ? 'oui' : 'non',
    $spawnPid ?? 'aucun',
    $diagnosticCount,
);
