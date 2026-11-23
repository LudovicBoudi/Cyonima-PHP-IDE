<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

use Cyonima\Gtk\Raw\Orientation;
use Cyonima\Runtime\AdwApplication;
use Cyonima\Runtime\Loop;
use Cyonima\Runtime\LspClient;
use Cyonima\Runtime\XdebugClient;
use Cyonima\UI\Box;
use Cyonima\UI\CodeEditor;
use Cyonima\UI\CompletionPopup;
use Cyonima\UI\DebugPanel;
use Cyonima\UI\FileBrowser;
use Cyonima\UI\IconButton;
use Cyonima\UI\Label;
use Cyonima\UI\Paned;
use Cyonima\UI\ScrolledWindow;
use Cyonima\UI\Terminal;
use Cyonima\UI\Theme;

/**
 * IDE Cyonima — disposition et thème inspirés de Visual Studio (dark).
 */

$auto = in_array('--auto', $argv, true);
$root = dirname(__DIR__) . '/examples';

$app = new AdwApplication('com.cyonima.ide-demo');
$window = $app->window('Cyonima', '');
$window->raw()->setDefaultSize(1280, 800);

$editor = new CodeEditor('php');
$terminal = new Terminal(5000);
$debugPanel = new DebugPanel();

Theme::apply($window, $editor);

// --- Chrome : onglet et barre de statut ------------------------------------
$tabLabel = (new Label('Bienvenue'))->cssClass('tab-label');
$tabDot = (new Label('●'))->cssClass('tab-dot')->visible(false);
$tabClose = (new IconButton('window-close-symbolic', 'Fermer'))->cssClass('tab-close');
$statusPos = (new Label('Ln 1, Col 1'))->cssClass('status-item');

$updateTab = function (?string $path, bool $isDirty) use ($tabLabel, $tabDot): void {
    $tabLabel->text($path !== null ? basename($path) : 'Bienvenue');
    $tabDot->visible($isDirty);
};

$updateCursor = function () use ($editor, $statusPos): void {
    $cursor = $editor->cursor();
    $statusPos->text(sprintf('Ln %d, Col %d', $cursor['line'] + 1, $cursor['character'] + 1));
};

// --- État ------------------------------------------------------------------
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

// --- Ouverture / modification / sauvegarde --------------------------------
$openFile = function (string $path) use (
    $editor,
    $window,
    $updateTab,
    $updateCursor,
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
    $updateTab($path, false);
    $updateCursor();

    if ($lsp !== null && $lspReady) {
        $lsp->didOpen('file://' . $path, $language, $editor->text());
    }
};

$editor->onChanged(function () use (
    &$dirty,
    &$loading,
    &$currentFile,
    $window,
    $updateTab,
    $updateCursor,
): void {
    if ($loading || $currentFile === null) {
        return;
    }

    $dirty = true;
    $window->subtitle(basename($currentFile) . ' •');
    $updateTab($currentFile, true);
    $updateCursor();
});

$save = function () use ($editor, $window, $updateTab, &$currentFile, &$dirty): bool {
    if ($currentFile === null) {
        $window->toast('Aucun fichier ouvert');
        return false;
    }

    file_put_contents($currentFile, $editor->text());
    $dirty = false;
    $window->subtitle(basename($currentFile));
    $updateTab($currentFile, false);
    $window->toast('Enregistré : ' . basename($currentFile));

    return true;
};

$run = function () use ($editor, $terminal, $window, $save, &$currentFile): void {
    if ($currentFile !== null && str_ends_with($currentFile, '.php')) {
        $save();
        $terminal->write('php ' . escapeshellarg($currentFile) . "\n");
        return;
    }

    $temp = tempnam(sys_get_temp_dir(), 'cyonima_');
    file_put_contents($temp, $editor->text());
    $terminal->write('php ' . escapeshellarg($temp) . "\n");
    $window->toast('Script temporaire exécuté');
};

// --- LSP optionnel ---------------------------------------------------------
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

$editor->onKeyPressed(function (CodeEditor $editor, int $keyval, int $state) use (
    $popup,
    $complete,
    $updateCursor,
): bool {
    Loop::after(0, $updateCursor);

    $control = ($state & 4) !== 0; // GDK_CONTROL_MASK

    if ($control && $keyval === 0x20) { // Ctrl+Espace
        $complete();
        return true;
    }

    if (!$popup->visible()) {
        return false;
    }

    return match ($keyval) {
        0xff1b => $popup->hide() ?? true,
        0xff0d, 0xff8d => $popup->chooseCurrent() ?? true,
        0xff52 => $popup->move(-1) ?? true,
        0xff54 => $popup->move(1) ?? true,
        default => false,
    };
});

// --- Débogage --------------------------------------------------------------
$debugPort = (int) (getenv('CYONIMA_XDEBUG_PORT') ?: XdebugClient::DEFAULT_PORT);
$debug = null;

// --- Barre d'activité ------------------------------------------------------
$activityBar = (new Box(Orientation::VERTICAL, 0))->cssClass('activity-bar');
$activityBar->raw()->setSizeRequest(48, -1);

$explorerButton = (new IconButton('folder-symbolic', 'Explorateur'))->cssClass('active');
$searchButton = (new IconButton('edit-find-symbolic', 'Recherche'));
$debugActivity = (new IconButton('applications-engineering-symbolic', 'Débogage'));
$settingsButton = (new IconButton('preferences-system-symbolic', 'Paramètres'));
$activitySpacer = (new Box(Orientation::VERTICAL, 0))->expand(false, true);

$activityBar->append($explorerButton, $searchButton, $debugActivity, $activitySpacer, $settingsButton);

// --- Panneau latéral -------------------------------------------------------
$browser = new FileBrowser($root, $openFile);

$panelTitle = (new Label('EXPLORER'))->cssClass('panel-header')->expand(true, false);
$refreshButton = (new IconButton('view-refresh-symbolic', 'Rafraîchir'))
    ->onClicked(fn () => $browser->refresh());
$panelHeader = (new Box(Orientation::HORIZONTAL, 4))
    ->cssClass('panel-header-bar')
    ->append($panelTitle, $refreshButton);

$explorerPage = (new ScrolledWindow($browser))->expand();
$debugPage = (new ScrolledWindow($debugPanel))->expand()->visible(false);

$panelPages = (new Box(Orientation::VERTICAL, 0))
    ->append($explorerPage, $debugPage)
    ->expand();

$sidePanel = (new Box(Orientation::VERTICAL, 0))
    ->cssClass('side-panel')
    ->append($panelHeader, $panelPages);
$sidePanel->raw()->setSizeRequest(270, -1);

$showExplorer = function () use (
    $explorerPage,
    $debugPage,
    $explorerButton,
    $debugActivity,
    $panelTitle,
): void {
    $explorerPage->visible(true);
    $debugPage->visible(false);
    $explorerButton->cssClass('active');
    $debugActivity->removeCssClass('active');
    $panelTitle->text('EXPLORER');
};

$showDebugPanel = function () use (
    $explorerPage,
    $debugPage,
    $explorerButton,
    $debugActivity,
    $panelTitle,
): void {
    $explorerPage->visible(false);
    $debugPage->visible(true);
    $debugActivity->cssClass('active');
    $explorerButton->removeCssClass('active');
    $panelTitle->text('DÉBOGAGE');
};

$explorerButton->onClicked(fn () => $showExplorer());
$debugActivity->onClicked(fn () => $showDebugPanel());
$searchButton->onClicked(fn () => $window->toast('Recherche à venir'));
$settingsButton->onClicked(fn () => $window->toast('Paramètres à venir'));

// --- Onglet, éditeur, terminal --------------------------------------------
$tab = (new Box(Orientation::HORIZONTAL, 6))
    ->cssClass('tab')
    ->cssClass('active')
    ->append($tabLabel, $tabDot, $tabClose);

$tabBar = (new Box(Orientation::HORIZONTAL, 0))
    ->cssClass('tab-bar')
    ->append($tab);

$tabClose->onClicked(function () use ($editor, $window, $updateTab, &$currentFile, &$dirty): void {
    $currentFile = null;
    $dirty = false;
    $editor->setText('');
    $window->subtitle('');
    $updateTab(null, false);
});

$editorArea = (new ScrolledWindow($editor))->expand();

$panelTabs = (new Box(Orientation::HORIZONTAL, 0))
    ->cssClass('panel-tabs')
    ->append(
        (new Label('PROBLÈMES'))->cssClass('panel-tab'),
        (new Label('SORTIE'))->cssClass('panel-tab'),
        (new Label('TERMINAL'))->cssClass('panel-tab')->cssClass('active'),
    );

$terminalPanel = (new Box(Orientation::VERTICAL, 0))
    ->append($panelTabs, $terminal->expand())
    ->expand();

$mainSplit = (new Paned(Orientation::VERTICAL, 520))
    ->start($editorArea)
    ->end($terminalPanel)
    ->expand();

$mainColumn = (new Box(Orientation::VERTICAL, 0))
    ->append($tabBar, $mainSplit)
    ->expand();

$body = (new Box(Orientation::HORIZONTAL, 0))
    ->append($activityBar, $sidePanel, $mainColumn)
    ->expand();

// --- Barre de statut -------------------------------------------------------
$statusBranch = (new Label('main'))->cssClass('status-item');
$statusSpaces = (new Label('Espaces : 4'))->cssClass('status-item');
$statusEncoding = (new Label('UTF-8'))->cssClass('status-item');
$statusPhp = (new Label('PHP 8.3'))->cssClass('status-item');
$statusFiller = (new Label(''))->expand(true, false);

$statusBar = (new Box(Orientation::HORIZONTAL, 0))
    ->cssClass('statusbar')
    ->append($statusBranch, $statusFiller, $statusPos, $statusSpaces, $statusEncoding, $statusPhp);

$content = (new Box(Orientation::VERTICAL, 0))->append($body, $statusBar);
$window->content($content);

// --- Barre de titre et actions --------------------------------------------
$menu = static fn (string $text): Label => (new Label($text))->cssClass('menu-item');

$saveButton = (new IconButton('document-save-symbolic', 'Enregistrer'))->onClicked(fn () => $save());
$runButton = (new IconButton('media-playback-start-symbolic', 'Exécuter'))->onClicked($run);

$debugButton = (new IconButton('applications-engineering-symbolic', 'Déboguer'))->onClicked(function () use (
    $debugPort,
    $window,
    $editor,
    $debugPanel,
    $openFile,
    $showDebugPanel,
    &$debug,
    &$currentFile,
): void {
    $showDebugPanel();

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
            $debug,
            $window,
            $editor,
            $debugPanel,
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

            $debug->stackGet(function (array $frames) use ($debugPanel): void {
                $debugPanel->showStack($frames);
            });

            $debug->contextGet(0, function (array $variables) use ($debugPanel): void {
                $debugPanel->showVariables($variables);
            });
        });

        $debug->on('running', function () use ($debugPanel): void {
            $debugPanel->clear();
        });

        $debug->on('stop', function () use ($window, $debugPanel): void {
            $debugPanel->clear();
            $window->toast('Session de débogage terminée');
        });
    }

    $window->toast('Xdebug en attente sur 127.0.0.1:' . $debugPort);
});

$window->headerStart(
    $menu('Fichier'),
    $menu('Édition'),
    $menu('Affichage'),
    $menu('Exécuter'),
    $menu('Aide'),
);
$window->headerEnd($runButton, $debugButton, $saveButton);

$window->present();

$sample = null;

if ($auto) {
    // On travaille sur une copie : le scénario automatique sauvegarde.
    $sample = sys_get_temp_dir() . '/cyonima_ide_demo.php';
    copy($root . '/hello.php', $sample);
    Loop::after(300, fn () => $openFile($sample));
    Loop::after(500, fn () => $runButton->click());
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
