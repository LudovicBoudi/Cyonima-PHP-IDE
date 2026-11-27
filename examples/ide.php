<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

use Cyonima\Gtk\Raw\Orientation;
use Cyonima\Compiler\CompilerException;
use Cyonima\Compiler\PhpCompiler;
use Cyonima\Runtime\AdwApplication;
use Cyonima\Runtime\Loop;
use Cyonima\Runtime\LspClient;
use Cyonima\Runtime\PhpIntellisense;
use Cyonima\Runtime\ProjectBuilder;
use Cyonima\Runtime\ProjectFactory;
use Cyonima\Runtime\XdebugClient;
use Cyonima\UI\AssetPanel;
use Cyonima\UI\Box;
use Cyonima\UI\CodeEditor;
use Cyonima\UI\CompletionPopup;
use Cyonima\UI\DebugPanel;
use Cyonima\App\DesignerWindow;
use Cyonima\UI\FileBrowser;
use Cyonima\UI\IconButton;
use Cyonima\UI\Label;
use Cyonima\UI\MenuBar;
use Cyonima\UI\Paned;
use Cyonima\UI\ProjectPropertiesPanel;
use Cyonima\UI\ScrolledWindow;
use Cyonima\UI\SearchPanel;
use Cyonima\UI\Terminal;
use Cyonima\UI\Theme;

/**
 * Cyonima IDE — disposition et thème inspirés de Visual Studio (dark).
 */

$auto = in_array('--auto', $argv, true);
$projectRoot = getenv('CYONIMA_PROJECT');
$hasProject = $projectRoot !== false && trim($projectRoot) !== '';
$root = $hasProject
    ? (realpath($projectRoot) ?: $projectRoot)
    : dirname(__DIR__) . '/examples';
$manifest = $hasProject ? ProjectFactory::manifest($root) : null;
$projectName = is_string($manifest['name'] ?? null) && $manifest['name'] !== ''
    ? $manifest['name']
    : ($hasProject ? basename($root) : 'Démonstration');
$projectLicense = is_string($manifest['license']['spdx'] ?? null)
    ? $manifest['license']['spdx']
    : '—';
$cyonimaHome = getenv('CYONIMA_HOME') ?: dirname(__DIR__);
$statusLicense = (new Label('Licence : ' . $projectLicense))->cssClass('status-item');

$app = new AdwApplication('com.cyonima.ide-demo');
$window = $app->window('Cyonima IDE', $projectName);
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
$openFile = function (string $path, int $line = 0) use (
    $editor,
    $window,
    $popup,
    $updateTab,
    $updateCursor,
    &$currentFile,
    &$dirty,
    &$loading,
    &$lsp,
    &$lspReady,
): void {
    $popup->hide();

    if (!is_file($path) || !is_readable($path)) {
        $window->toast('Fichier illisible');
        return;
    }

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $language = match ($extension) {
        'php' => 'php',
        'md' => 'markdown',
        'json' => 'json',
        'css' => 'css',
        'svg', 'xml' => 'xml',
        'js' => 'js',
        'html' => 'html',
        'sh' => 'sh',
        'txt' => 'text',
        default => 'php',
    };

    $loading = true;
    $editor->language($language);
    $editor->setText((string) file_get_contents($path));

    if ($line > 0) {
        $editor->moveCursorToLine($line - 1);
    } else {
        $editor->moveCursorToEnd();
    }

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

$complete = null;
$intellisenseTimer = null;
$suppressIntellisenseUntil = 0.0;

$editor->onChanged(function () use (
    &$dirty,
    &$loading,
    &$currentFile,
    $window,
    $updateTab,
    $updateCursor,
    &$complete,
    &$intellisenseTimer,
    &$suppressIntellisenseUntil,
    $editor,
    $popup,
): void {
    if ($loading || $currentFile === null) {
        return;
    }

    $dirty = true;
    $window->subtitle(basename($currentFile) . ' •');
    $updateTab($currentFile, true);
    $updateCursor();

    if (Loop::now() < $suppressIntellisenseUntil) {
        return;
    }

    // Filtrage local tant que le préfixe s'allonge : aucune nouvelle requête.
    if ($popup->active() && $popup->filterByPrefix($editor->wordPrefix())) {
        if ($intellisenseTimer !== null) {
            Loop::cancelTimer($intellisenseTimer);
        }

        $intellisenseTimer = Loop::after(90, function () use (&$intellisenseTimer, $popup): void {
            $intellisenseTimer = null;
            $popup->present();
        });

        return;
    }

    if ($intellisenseTimer !== null) {
        Loop::cancelTimer($intellisenseTimer);
    }

    $intellisenseTimer = Loop::after(180, function () use (
        &$intellisenseTimer,
        &$complete,
        &$suppressIntellisenseUntil,
        $editor,
    ): void {
        $intellisenseTimer = null;

        if (Loop::now() < $suppressIntellisenseUntil) {
            return;
        }

        if ($complete === null) {
            return;
        }

        $prefix = $editor->wordPrefix();
        $before = $editor->charBeforeCursor();
        $memberAccess = in_array($before, [46, 62, 58, 92], true); // . > : \\

        if (strlen($prefix) < 2 && !$memberAccess) {
            return;
        }

        $complete(true);
    });
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

$compileNativeFile = function () use ($window, $save, &$currentFile, $root, $hasProject): void {
    if (!$hasProject) {
        $window->toast('Ouvrez un projet pour compiler en natif.');
        return;
    }

    if ($currentFile === null || !str_ends_with($currentFile, '.php')) {
        $window->toast('Ouvrez un fichier PHP pour le compiler en natif.');
        return;
    }

    if (!$save()) {
        return;
    }

    $compiler = new PhpCompiler($root);

    try {
        $result = $compiler->compile($currentFile, null, 'elf');
        $window->toast('Binaire natif : ' . basename((string) $result['binaryPath']), 8000);
        return;
    } catch (CompilerException $exception) {
        $reason = $exception->getMessage();
    }

    try {
        $fallback = $compiler->compile($currentFile, null, 'c');
        $window->toast(
            'ELF impossible (' . substr($reason, 0, 140) . ') — C généré : ' . basename($fallback['cPath']),
            10000,
        );
    } catch (CompilerException $exception) {
        $window->toast('Compilation native : ' . substr($exception->getMessage(), 0, 220), 10000);
    }
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

$complete = function (bool $auto = false) use (
    $editor,
    $lsp,
    $popup,
    &$lspReady,
    &$currentFile,
    &$completionItems,
    &$suppressIntellisenseUntil,
    &$intellisenseTimer,
): void {
    if ($currentFile === null) {
        return;
    }

    $prefix = $editor->wordPrefix();
    $caret = $editor->cursorRect();

    $insert = function (array $item) use (
        $editor,
        &$suppressIntellisenseUntil,
        &$intellisenseTimer,
    ): void {
        $suppressIntellisenseUntil = Loop::now() + 400;

        if ($intellisenseTimer !== null) {
            Loop::cancelTimer($intellisenseTimer);
            $intellisenseTimer = null;
        }

        // Longueur courante : le préfixe peut avoir grandi depuis l'affichage.
        $currentPrefix = $editor->wordPrefix();
        $length = function_exists('mb_strlen')
            ? mb_strlen($currentPrefix, 'UTF-8')
            : strlen($currentPrefix);
        $editor->replacePrefix($length, $item['insertText'] ?? $item['label']);
    };

    // Sans serveur LSP : suggestions PHP intégrées.
    if ($lsp === null || !$lspReady) {
        if ($auto && strlen($prefix) < 2) {
            return;
        }

        $items = PhpIntellisense::suggest($prefix);
        $completionItems = count($items);
        if ($items !== []) {
            $popup->show($items, $insert, $caret, $prefix);
        }
        return;
    }

    $cursor = $editor->cursor();

    $lsp->request('textDocument/completion', [
        'textDocument' => ['uri' => 'file://' . $currentFile],
        'position' => ['line' => $cursor['line'], 'character' => $cursor['character']],
    ], function (mixed $result) use (
        $popup,
        $insert,
        $caret,
        $prefix,
        &$completionItems,
    ): void {
        $items = LspClient::normalizeCompletion($result);

        // Repli local si le serveur ne propose rien.
        if ($items === [] && $prefix !== '') {
            $items = PhpIntellisense::suggest($prefix);
        }

        $completionItems = count($items);
        if ($items === []) {
            return;
        }

        $popup->show($items, $insert, $caret, $prefix);
    });
};

$editor->onKeyPressed(function (CodeEditor $editor, int $keyval, int $state) use (
    $popup,
    $complete,
    $updateCursor,
    $save,
    $run,
): bool {
    Loop::after(0, $updateCursor);

    $control = ($state & 4) !== 0; // GDK_CONTROL_MASK
    $shift = ($state & 1) !== 0;   // GDK_SHIFT_MASK

    if ($control) {
        if ($keyval === 0x73) { // Ctrl+S
            $save();
            return true;
        }
        if ($keyval === 0x7a) { // Ctrl+Z / Ctrl+Shift+Z
            $shift ? $editor->redo() : $editor->undo();
            return true;
        }
        if ($keyval === 0x79) { // Ctrl+Y
            $editor->redo();
            return true;
        }
        if ($keyval === 0x61) { // Ctrl+A
            $editor->selectAll();
            return true;
        }
    }

    if ($keyval === 0xffc2) { // F5
        $run();
        return true;
    }

    if ($control && $keyval === 0x20) { // Ctrl+Espace
        $complete(false);
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

// --- Concepteur d'interface ------------------------------------------------
$designer = null;
$openDesigner = function () use ($app, $root, $hasProject, $window, $openFile, &$designer): void {
    if (!$hasProject) {
        $window->toast('Ouvrez un projet pour utiliser le concepteur.');
        return;
    }

    if ($designer !== null) {
        $designer->present();
        return;
    }

    $designer = new DesignerWindow(
        $app,
        $root,
        function (string $phpPath) use ($openFile, $window): void {
            $openFile($phpPath);
            $window->toast('Formulaire généré : ' . basename($phpPath));
        },
        function () use (&$designer): void {
            $designer = null;
        },
    );

    $designer->present();
};

// --- Export d'application autonome ----------------------------------------
$exportApplication = function (string $format = ProjectBuilder::FORMAT_APPIMAGE) use ($hasProject, $root, $cyonimaHome, $window): void {
    if (!$hasProject) {
        $window->toast('Ouvrez un projet pour exporter une application.');
        return;
    }

    $result = ProjectBuilder::start($cyonimaHome, $root, null, $format);

    if (!$result['ok'] || $result['process'] === null) {
        $window->toast($result['message'], 8000);
        return;
    }

    $window->toast($result['message'], 5000);

    $result['process']->onComplete(function ($process) use ($window): void {
        $build = $process->result();

        if ($build->succeeded()) {
            $lines = preg_split('/\R/', trim($build->stdout())) ?: [];
            $last = $lines === [] ? '' : (string) end($lines);
            $window->toast('Application autonome : ' . basename($last), 10000);
            return;
        }

        $error = trim($build->stderr) !== '' ? trim($build->stderr) : trim($build->stdout());
        $window->toast('Échec de l’export : ' . substr($error, 0, 220), 10000);
    });
};

// --- Barre d'activité ------------------------------------------------------
$activityBar = (new Box(Orientation::VERTICAL, 0))->cssClass('activity-bar');
$activityBar->raw()->setSizeRequest(48, -1);

$explorerButton = (new IconButton('folder-symbolic', 'Explorateur'))->cssClass('active');
$searchButton = (new IconButton('edit-find-symbolic', 'Recherche'));
$debugActivity = (new IconButton('applications-engineering-symbolic', 'Débogage'));
$assetsButton = (new IconButton('folder-pictures-symbolic', 'Assets'));
$designerActivity = (new IconButton('applications-graphics-symbolic', 'Concepteur d\'interface'));
$settingsButton = (new IconButton('preferences-system-symbolic', 'Propriétés du projet'));
$activitySpacer = (new Box(Orientation::VERTICAL, 0))->expand(false, true);

$activityBar->append(
    $explorerButton,
    $searchButton,
    $debugActivity,
    $assetsButton,
    $designerActivity,
    $activitySpacer,
    $settingsButton,
);

// --- Panneau latéral -------------------------------------------------------
$activePanel = 'explorer';
$browser = new FileBrowser($root, $openFile);
$assetPanel = new AssetPanel(
    $root,
    $openFile,
    function (string $message) use ($window): void {
        $window->toast($message);
    },
);
$projectProperties = new ProjectPropertiesPanel(
    $root,
    function (string $license) use ($statusLicense, $window): void {
        $statusLicense->text('Licence : ' . $license);
        $window->toast('Licence du projet mise à jour : ' . $license);
    },
);
$searchPanel = new SearchPanel(
    $root,
    function (string $path, int $line) use ($openFile): void {
        $openFile($path, $line);
    },
    function (array $report) use ($root, $window, $openFile, &$currentFile, &$dirty): void {
        if ($report['replacements'] === 0) {
            $window->toast('Aucun remplacement');
            return;
        }

        $window->toast(sprintf(
            '%d remplacement(s) dans %d fichier(s)',
            $report['replacements'],
            $report['changes'],
        ));

        if ($currentFile === null) {
            return;
        }

        foreach ($report['files'] as $file) {
            $absolute = $root . '/' . $file['path'];

            if (realpath($absolute) !== realpath($currentFile)) {
                continue;
            }

            if ($dirty) {
                $window->toast('Fichier courant modifié : non rechargé après remplacement', 8000);
                return;
            }

            $openFile($absolute);
            return;
        }
    },
);

$panelTitle = (new Label('EXPLORER'))->cssClass('panel-header')->expand(true, false);
$refreshButton = (new IconButton('view-refresh-symbolic', 'Rafraîchir'))
    ->onClicked(function () use ($browser, $assetPanel, $projectProperties, $searchPanel, &$activePanel): void {
        if ($activePanel === 'assets') {
            $assetPanel->refresh();
            return;
        }

        if ($activePanel === 'properties') {
            $projectProperties->refresh();
            return;
        }

        if ($activePanel === 'search') {
            $searchPanel->refresh();
            return;
        }

        $browser->refresh();
    });
$panelHeader = (new Box(Orientation::HORIZONTAL, 4))
    ->cssClass('panel-header-bar')
    ->append($panelTitle, $refreshButton);

$explorerPage = (new ScrolledWindow($browser))->expand();
$searchPage = (new ScrolledWindow($searchPanel))->expand()->visible(false);
$debugPage = (new ScrolledWindow($debugPanel))->expand()->visible(false);
$assetsPage = (new ScrolledWindow($assetPanel))->expand()->visible(false);
$propertiesPage = (new ScrolledWindow($projectProperties))->expand()->visible(false);

$panelPages = (new Box(Orientation::VERTICAL, 0))
    ->append($explorerPage, $searchPage, $debugPage, $assetsPage, $propertiesPage)
    ->expand();

$sidePanel = (new Box(Orientation::VERTICAL, 0))
    ->cssClass('side-panel')
    ->append($panelHeader, $panelPages);
$sidePanel->raw()->setSizeRequest(180, -1);

$showExplorer = function () use (
    $explorerPage,
    $searchPage,
    $debugPage,
    $assetsPage,
    $propertiesPage,
    $explorerButton,
    $searchButton,
    $debugActivity,
    $assetsButton,
    $settingsButton,
    $panelTitle,
    &$activePanel,
): void {
    $explorerPage->visible(true);
    $searchPage->visible(false);
    $debugPage->visible(false);
    $assetsPage->visible(false);
    $propertiesPage->visible(false);
    $explorerButton->cssClass('active');
    $searchButton->removeCssClass('active');
    $debugActivity->removeCssClass('active');
    $assetsButton->removeCssClass('active');
    $settingsButton->removeCssClass('active');
    $panelTitle->text('EXPLORER');
    $activePanel = 'explorer';
};

$showSearchPanel = function () use (
    $explorerPage,
    $searchPage,
    $debugPage,
    $assetsPage,
    $propertiesPage,
    $explorerButton,
    $searchButton,
    $debugActivity,
    $assetsButton,
    $settingsButton,
    $panelTitle,
    $searchPanel,
    &$activePanel,
): void {
    $explorerPage->visible(false);
    $searchPage->visible(true);
    $debugPage->visible(false);
    $assetsPage->visible(false);
    $propertiesPage->visible(false);
    $searchButton->cssClass('active');
    $explorerButton->removeCssClass('active');
    $debugActivity->removeCssClass('active');
    $assetsButton->removeCssClass('active');
    $settingsButton->removeCssClass('active');
    $panelTitle->text('RECHERCHE');
    $activePanel = 'search';
    $searchPanel->focus();
};

$showDebugPanel = function () use (
    $explorerPage,
    $searchPage,
    $debugPage,
    $assetsPage,
    $propertiesPage,
    $explorerButton,
    $searchButton,
    $debugActivity,
    $assetsButton,
    $settingsButton,
    $panelTitle,
    &$activePanel,
): void {
    $explorerPage->visible(false);
    $searchPage->visible(false);
    $debugPage->visible(true);
    $assetsPage->visible(false);
    $propertiesPage->visible(false);
    $debugActivity->cssClass('active');
    $explorerButton->removeCssClass('active');
    $searchButton->removeCssClass('active');
    $assetsButton->removeCssClass('active');
    $settingsButton->removeCssClass('active');
    $panelTitle->text('DÉBOGAGE');
    $activePanel = 'debug';
};

$showAssetsPanel = function () use (
    $explorerPage,
    $searchPage,
    $debugPage,
    $assetsPage,
    $propertiesPage,
    $explorerButton,
    $searchButton,
    $debugActivity,
    $assetsButton,
    $settingsButton,
    $panelTitle,
    $assetPanel,
    &$activePanel,
): void {
    $explorerPage->visible(false);
    $searchPage->visible(false);
    $debugPage->visible(false);
    $assetsPage->visible(true);
    $propertiesPage->visible(false);
    $assetsButton->cssClass('active');
    $explorerButton->removeCssClass('active');
    $searchButton->removeCssClass('active');
    $debugActivity->removeCssClass('active');
    $settingsButton->removeCssClass('active');
    $panelTitle->text('ASSETS');
    $activePanel = 'assets';
    $assetPanel->refresh();
};

$showPropertiesPanel = function () use (
    $explorerPage,
    $searchPage,
    $debugPage,
    $assetsPage,
    $propertiesPage,
    $explorerButton,
    $searchButton,
    $debugActivity,
    $assetsButton,
    $settingsButton,
    $panelTitle,
    $projectProperties,
    &$activePanel,
): void {
    $explorerPage->visible(false);
    $searchPage->visible(false);
    $debugPage->visible(false);
    $assetsPage->visible(false);
    $propertiesPage->visible(true);
    $settingsButton->cssClass('active');
    $explorerButton->removeCssClass('active');
    $searchButton->removeCssClass('active');
    $debugActivity->removeCssClass('active');
    $assetsButton->removeCssClass('active');
    $panelTitle->text('PROPRIÉTÉS');
    $activePanel = 'properties';
    $projectProperties->refresh();
};

$explorerButton->onClicked(fn () => $showExplorer());
$searchButton->onClicked(fn () => $showSearchPanel());
$debugActivity->onClicked(fn () => $showDebugPanel());
$assetsButton->onClicked(fn () => $showAssetsPanel());
$designerActivity->onClicked(fn () => $openDesigner());
$settingsButton->onClicked(fn () => $showPropertiesPanel());

// --- Onglet, éditeur, terminal --------------------------------------------
$tab = (new Box(Orientation::HORIZONTAL, 6))
    ->cssClass('tab')
    ->cssClass('active')
    ->append($tabLabel, $tabDot, $tabClose);

$tabBar = (new Box(Orientation::HORIZONTAL, 0))
    ->cssClass('tab-bar')
    ->append($tab);

$closeTab = function () use ($editor, $window, $popup, $updateTab, &$currentFile, &$dirty): void {
    $popup->hide();
    $currentFile = null;
    $dirty = false;
    $editor->setText('');
    $window->subtitle('');
    $updateTab(null, false);
};

$tabClose->onClicked($closeTab);

$editorArea = (new ScrolledWindow($editor))->expand();

$panelTabs = (new Box(Orientation::HORIZONTAL, 0))
    ->cssClass('panel-tabs')
    ->append(
        (new Label('PROBLÈMES'))->cssClass('panel-tab'),
        (new Label('SORTIE'))->cssClass('panel-tab'),
        (new Label('TERMINAL'))->cssClass('panel-tab')->cssClass('active'),
    );

$terminalVisible = true;
$terminalPanel = (new Box(Orientation::VERTICAL, 0))
    ->append($panelTabs, $terminal->expand())
    ->expand();

$toggleTerminal = function () use ($terminalPanel, &$terminalVisible): void {
    $terminalVisible = !$terminalVisible;
    $terminalPanel->visible($terminalVisible);
};

$mainSplit = (new Paned(Orientation::VERTICAL, 520))
    ->start($editorArea)
    ->end($terminalPanel)
    ->expand();

$mainColumn = (new Box(Orientation::VERTICAL, 0))
    ->append($tabBar, $mainSplit)
    ->expand();

$workspace = (new Paned(Orientation::HORIZONTAL, 270))
    ->start($sidePanel)
    ->end($mainColumn)
    ->expand()
    ->resizeStart(false)
    ->shrinkStart(true);

$body = (new Box(Orientation::HORIZONTAL, 0))
    ->append($activityBar, $workspace)
    ->expand();

// --- Barre de statut -------------------------------------------------------
$statusProject = (new Label($projectName))->cssClass('status-item');
$statusBranch = (new Label('main'))->cssClass('status-item');
$statusSpaces = (new Label('Espaces : 4'))->cssClass('status-item');
$statusEncoding = (new Label('UTF-8'))->cssClass('status-item');
$statusPhp = (new Label('PHP 8.3'))->cssClass('status-item');
$statusFiller = (new Label(''))->expand(true, false);

$statusBar = (new Box(Orientation::HORIZONTAL, 0))
    ->cssClass('statusbar')
    ->append(
        $statusProject,
        $statusLicense,
        $statusBranch,
        $statusFiller,
        $statusPos,
        $statusSpaces,
        $statusEncoding,
        $statusPhp,
    );

$content = (new Box(Orientation::VERTICAL, 0))->append($body, $statusBar);
$window->content($content);

// --- Barre de titre et actions --------------------------------------------
$saveButton = (new IconButton('document-save-symbolic', 'Enregistrer'))->onClicked(fn () => $save());
$exportButton = (new IconButton('package-x-generic-symbolic', 'Exporter l’application autonome'))
    ->onClicked(fn () => $exportApplication());
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

$menuBar = (new MenuBar())
    ->addMenu('Fichier', [
        ['label' => 'Enregistrer', 'shortcut' => 'Ctrl+S', 'action' => $save],
        ['label' => 'Fermer l\'onglet', 'action' => $closeTab],
        ['separator' => true],
        ['label' => 'Exporter l’application (AppImage)', 'action' => $exportApplication],
        ['label' => 'Exporter l’application (Flatpak)', 'action' => fn () => $exportApplication(ProjectBuilder::FORMAT_FLATPAK)],
        ['separator' => true],
        ['label' => 'Quitter', 'action' => fn () => $window->raw()->close()],
    ])
    ->addMenu('Édition', [
        ['label' => 'Annuler', 'shortcut' => 'Ctrl+Z', 'action' => fn () => $editor->undo()],
        ['label' => 'Rétablir', 'shortcut' => 'Ctrl+Shift+Z', 'action' => fn () => $editor->redo()],
        ['separator' => true],
        ['label' => 'Tout sélectionner', 'shortcut' => 'Ctrl+A', 'action' => fn () => $editor->selectAll()],
    ])
    ->addMenu('Affichage', [
        ['label' => 'Explorateur', 'action' => $showExplorer],
        ['label' => 'Recherche', 'action' => $showSearchPanel],
        ['label' => 'Débogage', 'action' => $showDebugPanel],
        ['label' => 'Assets', 'action' => $showAssetsPanel],
        ['label' => 'Propriétés du projet', 'action' => $showPropertiesPanel],
        ['label' => 'Concepteur d\'interface', 'action' => $openDesigner],
        ['separator' => true],
        ['label' => 'Afficher / masquer le terminal', 'action' => $toggleTerminal],
    ])
    ->addMenu('Exécuter', [
        ['label' => 'Exécuter le fichier', 'shortcut' => 'F5', 'action' => fn () => $run()],
        ['label' => 'Compiler le fichier en natif', 'action' => $compileNativeFile],
        ['label' => 'Déboguer', 'action' => fn () => $debugButton->click()],
        ['separator' => true],
        ['label' => 'Arrêter le débogage', 'action' => function () use (&$debug, $debugPanel, $window): void {
            if ($debug === null) {
                $window->toast('Aucune session de débogage');
                return;
            }

            $debug->close();
            $debug = null;
            $debugPanel->clear();
            $window->toast('Session de débogage arrêtée');
        }],
    ])
    ->addMenu('Aide', [
        ['label' => 'Raccourcis clavier', 'action' => fn () => $window->toast(
            'Ctrl+S Enregistrer · Ctrl+Z Annuler · Ctrl+Y Rétablir · Ctrl+A Tout sélectionner · Ctrl+Espace IntelliSense · F5 Exécuter',
            8000,
        )],
        ['label' => 'À propos de Cyonima IDE', 'action' => fn () => $window->toast(
            'Cyonima IDE 0.1.0 · MIT · PHP 8.3 + GTK4',
            6000,
        )],
        ['separator' => true],
        ['label' => 'Licence MIT', 'action' => fn () => $window->toast(
            'Cyonima IDE est distribué sous licence MIT, en open source.',
            6000,
        )],
    ]);

$window->headerStart($menuBar);
$window->headerEnd($runButton, $debugButton, $exportButton, $saveButton);

$window->present();

$sample = null;

if ($auto) {
    // On travaille sur une copie : le scénario automatique sauvegarde.
    $sample = sys_get_temp_dir() . '/cyonima_ide_demo.php';
    $source = $root . '/hello.php';

    if (is_file($source) && is_readable($source)) {
        copy($source, $sample);
    } else {
        file_put_contents($sample, "<?php\n\ndeclare(strict_types=1);\n\necho \"Cyonima\\n\";\n");
    }

    Loop::after(300, fn () => $openFile($sample));
    Loop::after(500, fn () => $runButton->click());
    Loop::after(700, function () use ($editor): void {
        $editor->setText($editor->text() . "\nstr");
        $editor->moveCursorToEnd();
    });
    Loop::after(950, fn () => $complete(false));
    Loop::after(1250, fn () => $popup->chooseCurrent());
    Loop::after(1600, fn () => $save());
    Loop::after(1950, fn () => $window->raw()->close());
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
