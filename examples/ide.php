<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

use Cyonima\Gtk\Raw\Orientation;
use Cyonima\Compiler\CompilerException;
use Cyonima\Compiler\PhpCompiler;
use Cyonima\Runtime\AdwApplication;
use Cyonima\Runtime\Diagnostics;
use Cyonima\Runtime\GitRepository;
use Cyonima\Runtime\Loop;
use Cyonima\Runtime\LspClient;
use Cyonima\Runtime\PhpIntellisense;
use Cyonima\Runtime\ProjectBuilder;
use Cyonima\Runtime\ProjectFactory;
use Cyonima\Runtime\ScrollSync;
use Cyonima\Runtime\WorkspaceWatcher;
use Cyonima\Runtime\XdebugClient;
use Cyonima\UI\AssetPanel;
use Cyonima\UI\Box;
use Cyonima\UI\Button;
use Cyonima\UI\CodeEditor;
use Cyonima\UI\CommandPalette;
use Cyonima\UI\CompletionPopup;
use Cyonima\UI\DebugPanel;
use Cyonima\UI\DiffPanel;
use Cyonima\App\DesignerWindow;
use Cyonima\UI\FileBrowser;
use Cyonima\UI\FindBar;
use Cyonima\UI\GitPanel;
use Cyonima\UI\IconButton;
use Cyonima\UI\Label;
use Cyonima\UI\MarkdownPreview;
use Cyonima\UI\MenuBar;
use Cyonima\UI\Paned;
use Cyonima\UI\OutputPanel;
use Cyonima\UI\ProblemsPanel;
use Cyonima\UI\ProjectPropertiesPanel;
use Cyonima\UI\QuickOpen;
use Cyonima\UI\ScrolledWindow;
use Cyonima\UI\SearchPanel;
use Cyonima\UI\SymbolSearch;
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

// --- État ------------------------------------------------------------------
$spawnPid = null;
$currentFile = null;
$dirty = false;
$loading = false;

$lsp = null;
$lspReady = false;
/** @var array<string, true> $lspDocuments */
$lspDocuments = [];
$diagnosticCount = 0;
$completionItems = 0;

$popup = new CompletionPopup($editor);
$findBar = new FindBar($editor);

// --- Aperçu Markdown -------------------------------------------------------
$markdownPreview = new MarkdownPreview();
$previewVisible = false;
$editorScrolled = (new ScrolledWindow($editor))->expand();
$previewPage = (new ScrolledWindow($markdownPreview))->expand()->visible(false);
$editorSplit = (new Paned(Orientation::HORIZONTAL, 760))
    ->start($editorScrolled)
    ->end($previewPage)
    ->expand()
    ->resizeStart(false);
$scrollSync = new ScrollSync(
    $editorScrolled->raw()->handle(),
    $previewPage->raw()->handle(),
);
$scrollSync->setMapper(function (bool $fromSource, float $fraction) use (
    $editor,
    $markdownPreview,
): float {
    $editorLines = max(1, $editor->lineCount());
    $previewLines = max(1, $markdownPreview->renderedLineCount());

    if ($fromSource) {
        $sourceLine = (int) round($fraction * ($editorLines - 1));
        $previewLine = $markdownPreview->previewLineForSourceLine($sourceLine);

        return $previewLines <= 1 ? 0.0 : $previewLine / ($previewLines - 1);
    }

    $previewLine = (int) round($fraction * ($previewLines - 1));
    $sourceLine = $markdownPreview->sourceLineForPreviewLine($previewLine);

    return $editorLines <= 1 ? 0.0 : $sourceLine / ($editorLines - 1);
});

$togglePreview = function () use (
    $window,
    $editor,
    $markdownPreview,
    $previewPage,
    $editorSplit,
    $scrollSync,
    &$previewVisible,
    &$currentFile,
): void {
    if ($currentFile === null
        || strtolower(pathinfo($currentFile, PATHINFO_EXTENSION)) !== 'md'
    ) {
        $window->toast('Aperçu Markdown : ouvrez un fichier .md');
        return;
    }

    $previewVisible = !$previewVisible;

    if (!$previewVisible) {
        $previewPage->visible(false);
        return;
    }

    $markdownPreview->render($editor->text());
    $previewPage->visible(true);
    $width = $editorSplit->raw()->getWidth();

    if ($width > 0) {
        $editorSplit->position((int) ($width * 0.55));
    }

    Loop::after(0, fn () => $scrollSync->syncFromSource());
};

$syncPreview = function () use (
    $editor,
    $markdownPreview,
    $previewPage,
    $scrollSync,
    &$previewVisible,
    &$currentFile,
): void {
    if (!$previewVisible) {
        return;
    }

    if ($currentFile !== null
        && strtolower(pathinfo($currentFile, PATHINFO_EXTENSION)) === 'md'
    ) {
        $markdownPreview->render($editor->text());
        $previewPage->visible(true);
        Loop::after(0, fn () => $scrollSync->syncFromSource());
        return;
    }

    $previewVisible = false;
    $previewPage->visible(false);
};

$editor->onChanged(function () use ($editor, $markdownPreview, &$previewVisible): void {
    if ($previewVisible) {
        $markdownPreview->render($editor->text());
    }
});

$statusPos = (new Label('Ln 1, Col 1'))->cssClass('status-item');

$updateCursor = function () use ($editor, $statusPos): void {
    $cursor = $editor->cursor();
    $statusPos->text(sprintf('Ln %d, Col %d', $cursor['line'] + 1, $cursor['character'] + 1));
};

// --- Documents ouverts (onglets) -------------------------------------------
/** @var array<string, array{path: string, content: string, dirty: bool, line: int, character: int, language: string}> $documents */
$documents = [];
/** @var list<string> $documentOrder */
$documentOrder = [];
/** @var list<Box> $tabWidgets */
$tabWidgets = [];
/** @var array<string, Label> $tabDots */
$tabDots = [];
/** @var array<string, Box> $tabContainers */
$tabContainers = [];

$tabBar = (new Box(Orientation::HORIZONTAL, 0))->cssClass('tab-bar');

$activateDocument = null;
$closeDocument = null;

$storeCurrentDocument = function () use (
    $editor,
    &$documents,
    &$currentFile,
    &$dirty,
): void {
    if ($currentFile === null || !isset($documents[$currentFile])) {
        return;
    }

    $cursor = $editor->cursor();
    $documents[$currentFile]['content'] = $editor->text();
    $documents[$currentFile]['dirty'] = $dirty;
    $documents[$currentFile]['line'] = $cursor['line'];
    $documents[$currentFile]['character'] = $cursor['character'];
};

$renderTabs = function () use (
    $tabBar,
    $documents,
    $documentOrder,
    &$currentFile,
    &$tabWidgets,
    &$tabDots,
    &$tabContainers,
    &$activateDocument,
    &$closeDocument,
): void {
    foreach ($tabWidgets as $widget) {
        $tabBar->remove($widget);
    }

    $tabWidgets = [];
    $tabDots = [];
    $tabContainers = [];

    foreach ($documentOrder as $path) {
        if (!isset($documents[$path])) {
            continue;
        }

        $label = (new Label(basename($path)))->cssClass('tab-label');
        $dot = (new Label('●'))->cssClass('tab-dot')->visible($documents[$path]['dirty']);
        $close = (new IconButton('window-close-symbolic', 'Fermer'))->cssClass('tab-close');
        $close->onClicked(fn () => ($closeDocument)($path));

        $tab = (new Box(Orientation::HORIZONTAL, 6))
            ->cssClass('tab')
            ->tooltip($path)
            ->append($label, $dot, $close);

        if ($path === $currentFile) {
            $tab->cssClass('active');
        }

        $tab->onClick(fn () => ($activateDocument)($path));

        $tabBar->append($tab);
        $tabWidgets[] = $tab;
        $tabDots[$path] = $dot;
        $tabContainers[$path] = $tab;
    }
};

$updateTab = function (?string $path, bool $isDirty) use (
    &$documents,
    &$currentFile,
    &$dirty,
    &$tabDots,
    $window,
): void {
    if ($path !== null) {
        if (isset($documents[$path])) {
            $documents[$path]['dirty'] = $isDirty;
        }

        if ($path === $currentFile) {
            $dirty = $isDirty;
        }

        if (isset($tabDots[$path])) {
            $tabDots[$path]->visible($isDirty);
        }

        $window->subtitle(basename($path) . ($isDirty ? ' •' : ''));
        return;
    }

    $window->subtitle('');
};

$activateDocument = function (string $path, int $line = 0) use (
    $editor,
    $window,
    $popup,
    $updateCursor,
    &$documents,
    &$currentFile,
    &$dirty,
    &$loading,
    &$lsp,
    &$lspReady,
    &$lspDocuments,
    &$storeCurrentDocument,
    &$renderTabs,
    $syncPreview,
): void {
    if (!isset($documents[$path])) {
        return;
    }

    $switched = $currentFile !== $path;

    if ($switched) {
        ($storeCurrentDocument)();

        $document = $documents[$path];
        $loading = true;
        $editor->language($document['language']);
        $editor->setText($document['content']);
        $loading = false;

        $currentFile = $path;
        $dirty = $document['dirty'];

        if ($lsp !== null && $lspReady && !isset($lspDocuments[$path])) {
            $lsp->didOpen('file://' . $path, $document['language'], $document['content']);
            $lspDocuments[$path] = true;
        }
    }

    if ($line > 0) {
        $editor->moveCursorToLine($line - 1);
    } elseif ($switched) {
        $document = $documents[$path];
        $editor->moveCursorTo($document['line'], $document['character']);
    }

    $popup->hide();
    $window->subtitle(basename($path) . ($dirty ? ' •' : ''));
    ($renderTabs)();
    $updateCursor();
    $syncPreview();
    $editor->grabFocus();
};

$closeDocument = function (string $path) use (
    $editor,
    $window,
    $popup,
    $updateCursor,
    &$documents,
    &$documentOrder,
    &$currentFile,
    &$dirty,
    &$loading,
    &$lsp,
    &$lspReady,
    &$lspDocuments,
    &$renderTabs,
    &$activateDocument,
    $syncPreview,
): void {
    if (!isset($documents[$path])) {
        return;
    }

    if ($documents[$path]['dirty']) {
        $window->toast('Enregistrez ou annulez les modifications avant de fermer');
        return;
    }

    $index = array_search($path, $documentOrder, true);
    unset($documents[$path]);
    $documentOrder = array_values(array_filter(
        $documentOrder,
        fn (string $candidate): bool => $candidate !== $path,
    ));

    if ($lsp !== null && $lspReady && isset($lspDocuments[$path])) {
        $lsp->didClose('file://' . $path);
        unset($lspDocuments[$path]);
    }

    if ($currentFile !== $path) {
        ($renderTabs)();
        return;
    }

    $popup->hide();
    $currentFile = null;
    $dirty = false;
    $syncPreview();

    $next = $documentOrder[$index] ?? null;

    if ($next === null && $documentOrder !== []) {
        $next = $documentOrder[count($documentOrder) - 1];
    }

    if ($next !== null) {
        ($activateDocument)($next);
        return;
    }

    $loading = true;
    $editor->setText('');
    $loading = false;
    $editor->language('php');
    $window->subtitle('');
    ($renderTabs)();
    $updateCursor();
};

$nextTab = function (int $direction) use (
    &$documentOrder,
    &$currentFile,
    &$activateDocument,
): void {
    if (count($documentOrder) < 2 || $currentFile === null) {
        return;
    }

    $index = array_search($currentFile, $documentOrder, true);

    if ($index === false) {
        return;
    }

    $count = count($documentOrder);
    $target = ($index + $direction + $count) % $count;
    ($activateDocument)($documentOrder[$target]);
};

$closeTab = function () use (&$currentFile, &$closeDocument): void {
    if ($currentFile !== null) {
        ($closeDocument)($currentFile);
    }
};

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
$openFile = function (string $path, int $line = 0, bool $reload = false) use (
    $editor,
    $window,
    $popup,
    &$documents,
    &$documentOrder,
    &$currentFile,
    &$dirty,
    &$loading,
    &$activateDocument,
): void {
    $popup->hide();

    if (!is_file($path) || !is_readable($path)) {
        $window->toast('Fichier illisible');
        return;
    }

    $path = realpath($path) ?: $path;

    if (!isset($documents[$path])) {
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

        $documents[$path] = [
            'path' => $path,
            'content' => (string) file_get_contents($path),
            'dirty' => false,
            'line' => 0,
            'character' => 0,
            'language' => $language,
        ];
        $documentOrder[] = $path;
    } elseif ($reload) {
        $content = (string) file_get_contents($path);
        $documents[$path]['content'] = $content;
        $documents[$path]['dirty'] = false;

        if ($currentFile === $path) {
            $loading = true;
            $editor->setText($content);
            $loading = false;
            $dirty = false;
        }
    }

    ($activateDocument)($path, $line);
};

$quickOpen = new QuickOpen($window, $root, function (string $path) use ($openFile): void {
    $openFile($path);
});

$symbolSearch = new SymbolSearch(
    $window,
    $root,
    function (string $path, int $line) use ($root, $openFile): void {
        $openFile($root . DIRECTORY_SEPARATOR . $path, $line);
    },
);

$commandPalette = null;

$diagnostics = new Diagnostics();
$problemsPanel = new ProblemsPanel(function (string $path, int $line) use ($openFile): void {
    $openFile($path, $line);
});
$refreshProblemsTab = null;

$outputPanel = new OutputPanel();
$outputPanel->append('Cyonima IDE prêt', 'IDE');
$showOutput = null;

$diffPanel = new DiffPanel();
$showDiff = null;

$statusBranch = null;
$gitPanel = new GitPanel(
    $root,
    function (string $path) use ($openFile): void {
        $openFile($path);
    },
    function (string $branch, int $changes) use (&$statusBranch): void {
        if ($statusBranch !== null) {
            $statusBranch->text($branch);
        }
    },
    function (string $message, bool $error) use ($window, $outputPanel, &$showOutput): void {
        $outputPanel->append($message, 'Git');
        $window->toast($message, $error ? 6000 : 3000);

        if ($error && $showOutput !== null) {
            ($showOutput)();
        }
    },
    function (string $path, string $diff) use ($diffPanel, &$showDiff): void {
        $diffPanel->show($path, $diff);

        if ($showDiff !== null) {
            ($showDiff)();
        }
    },
    function (string $branch) use (&$currentFile, &$dirty, $openFile, $window): void {
        $window->toast('Branche courante : ' . $branch);

        if ($currentFile !== null && !$dirty && is_file($currentFile)) {
            $openFile($currentFile, 0, true);
        }
    },
);
$refreshGit = function () use ($gitPanel): void {
    $gitPanel->refresh();
};
$showGitDiff = function () use ($gitPanel, $diffPanel, &$showDiff): void {
    $diffPanel->show('Projet', $gitPanel->diff());

    if ($showDiff !== null) {
        ($showDiff)();
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

$save = function () use (
    $editor,
    $window,
    $updateTab,
    $refreshGit,
    $symbolSearch,
    &$documents,
    &$currentFile,
    &$dirty,
): bool {
    if ($currentFile === null) {
        $window->toast('Aucun fichier ouvert');
        return false;
    }

    $content = $editor->text();
    file_put_contents($currentFile, $content);
    $symbolSearch->invalidate($currentFile);
    $dirty = false;

    if (isset($documents[$currentFile])) {
        $documents[$currentFile]['content'] = $content;
        $documents[$currentFile]['dirty'] = false;
    }

    $window->subtitle(basename($currentFile));
    $updateTab($currentFile, false);
    $refreshGit();
    $window->toast('Enregistré : ' . basename($currentFile));

    return true;
};

$run = function () use ($editor, $terminal, $window, $save, $outputPanel, &$currentFile): void {
    if ($currentFile !== null && str_ends_with($currentFile, '.php')) {
        $save();
        $terminal->write('php ' . escapeshellarg($currentFile) . "\n");
        $outputPanel->append('php ' . $currentFile . ' (terminal)', 'Exécuter');
        return;
    }

    $temp = tempnam(sys_get_temp_dir(), 'cyonima_');
    file_put_contents($temp, $editor->text());
    $terminal->write('php ' . escapeshellarg($temp) . "\n");
    $outputPanel->append('php ' . $temp . ' (terminal)', 'Exécuter');
    $window->toast('Script temporaire exécuté');
};

$compileNativeFile = function () use (
    $window,
    $save,
    $outputPanel,
    &$showOutput,
    &$currentFile,
    $root,
    $hasProject,
): void {
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

    if ($showOutput !== null) {
        ($showOutput)();
    }

    $outputPanel->append('Compilation native : ' . $currentFile, 'Compiler');
    $compiler = new PhpCompiler($root);

    try {
        $result = $compiler->compile($currentFile, null, 'elf');
        $binary = (string) $result['binaryPath'];
        $outputPanel->append('ELF : ' . $binary, 'Compiler');
        $window->toast('Binaire natif : ' . basename($binary), 8000);
        return;
    } catch (CompilerException $exception) {
        $reason = $exception->getMessage();
        $outputPanel->append('ELF impossible : ' . $reason, 'Compiler');
    }

    try {
        $fallback = $compiler->compile($currentFile, null, 'c');
        $cPath = (string) $fallback['cPath'];
        $outputPanel->append('C généré : ' . $cPath, 'Compiler');
        $window->toast(
            'ELF impossible (' . substr($reason, 0, 140) . ') — C généré : ' . basename($cPath),
            10000,
        );
    } catch (CompilerException $exception) {
        $outputPanel->append('Échec : ' . $exception->getMessage(), 'Compiler');
        $window->toast('Compilation native : ' . substr($exception->getMessage(), 0, 220), 10000);
    }
};

// --- LSP optionnel ---------------------------------------------------------
$lspCommand = getenv('CYONIMA_LSP');
if ($lspCommand !== false && trim($lspCommand) !== '') {
    $lsp = LspClient::start(preg_split('/\s+/', trim($lspCommand)) ?: []);
    $lsp->attach();
    $lsp->on('textDocument/publishDiagnostics', function (array $params) use (
        $window,
        $diagnostics,
        $problemsPanel,
        &$refreshProblemsTab,
        &$diagnosticCount,
    ): void {
        $incoming = is_array($params['diagnostics'] ?? null) ? $params['diagnostics'] : [];
        $diagnosticCount += count($incoming);

        $diagnostics->update((string) ($params['uri'] ?? ''), $incoming);
        $problemsPanel->set($diagnostics->all());

        if ($refreshProblemsTab !== null) {
            ($refreshProblemsTab)();
        }

        if ($diagnostics->count() > 0) {
            $window->toast('LSP : ' . $diagnostics->summary(), 4000);
        }
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
    $closeTab,
    $nextTab,
    $quickOpen,
    $symbolSearch,
    $findBar,
    $togglePreview,
    &$commandPalette,
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
        if ($keyval === 0x70) { // Ctrl+P / Ctrl+Shift+P
            if ($shift) {
                $commandPalette?->present();
            } else {
                $quickOpen->present();
            }
            return true;
        }
        if ($keyval === 0x74) { // Ctrl+T
            $symbolSearch->present();
            return true;
        }
        if ($keyval === 0x66) { // Ctrl+F
            $findBar->show();
            return true;
        }
        if ($keyval === 0x68) { // Ctrl+H
            $findBar->showReplace();
            return true;
        }
        if ($keyval === 0x76 && $shift) { // Ctrl+Shift+V
            $togglePreview();
            return true;
        }
        if ($keyval === 0x77) { // Ctrl+W
            $closeTab();
            return true;
        }
        if ($keyval === 0xff09 || $keyval === 0xfe20) { // Ctrl+Tab
            $nextTab($shift || $keyval === 0xfe20 ? -1 : 1);
            return true;
        }
        if ($keyval === 0xff56) { // Ctrl+Page_Down
            $nextTab(1);
            return true;
        }
        if ($keyval === 0xff55) { // Ctrl+Page_Up
            $nextTab(-1);
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
$exportApplication = function (string $format = ProjectBuilder::FORMAT_APPIMAGE) use (
    $hasProject,
    $root,
    $cyonimaHome,
    $window,
    $outputPanel,
    &$showOutput,
): void {
    if (!$hasProject) {
        $window->toast('Ouvrez un projet pour exporter une application.');
        return;
    }

    $result = ProjectBuilder::start($cyonimaHome, $root, null, $format);
    $formatLabel = strtoupper($format);

    if (!$result['ok'] || $result['process'] === null) {
        $outputPanel->append($result['message'], 'Export');
        $window->toast($result['message'], 8000);
        return;
    }

    if ($showOutput !== null) {
        ($showOutput)();
    }

    $outputPanel->append(sprintf('Export %s : %s', $formatLabel, $root), 'Export');
    $window->toast($result['message'], 5000);

    $result['process']->onComplete(function ($process) use ($window, $outputPanel, $formatLabel): void {
        $build = $process->result();

        if (trim($build->stdout) !== '') {
            $outputPanel->append($build->stdout, 'Export');
        }

        if (trim($build->stderr) !== '') {
            $outputPanel->append($build->stderr, 'Export');
        }

        $outputPanel->append(sprintf(
            'Terminé avec le code %d en %d ms',
            $build->exitCode,
            (int) $build->durationMs,
        ), 'Export');

        if ($build->succeeded()) {
            $lines = preg_split('/\R/', trim($build->stdout)) ?: [];
            $last = $lines === [] ? '' : (string) end($lines);
            $window->toast('Application autonome : ' . basename($last), 10000);
            return;
        }

        $error = trim($build->stderr) !== '' ? trim($build->stderr) : trim($build->stdout);
        $window->toast('Échec de l’export : ' . substr($error, 0, 220), 10000);
    });
};

// --- Barre d'activité ------------------------------------------------------
$activityBar = (new Box(Orientation::VERTICAL, 0))->cssClass('activity-bar');
$activityBar->raw()->setSizeRequest(48, -1);

$explorerButton = (new IconButton('folder-symbolic', 'Explorateur'))->cssClass('active');
$searchButton = (new IconButton('edit-find-symbolic', 'Recherche'));
$gitButton = (new IconButton('view-list-symbolic', 'Git'));
$debugActivity = (new IconButton('applications-engineering-symbolic', 'Débogage'));
$assetsButton = (new IconButton('folder-pictures-symbolic', 'Assets'));
$designerActivity = (new IconButton('applications-graphics-symbolic', 'Concepteur d\'interface'));
$settingsButton = (new IconButton('preferences-system-symbolic', 'Propriétés du projet'));
$activitySpacer = (new Box(Orientation::VERTICAL, 0))->expand(false, true);

$activityBar->append(
    $explorerButton,
    $searchButton,
    $gitButton,
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
    function (array $report) use (
        $root,
        $window,
        $openFile,
        $symbolSearch,
        &$currentFile,
        &$dirty,
    ): void {
        if ($report['replacements'] === 0) {
            $window->toast('Aucun remplacement');
            return;
        }

        foreach ($report['files'] as $file) {
            $symbolSearch->invalidate($root . DIRECTORY_SEPARATOR . $file['path']);
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

            $openFile($absolute, 0, true);
            return;
        }
    },
);

$panelTitle = (new Label('EXPLORER'))->cssClass('panel-header')->expand(true, false);
$refreshButton = (new IconButton('view-refresh-symbolic', 'Rafraîchir'))
    ->onClicked(function () use (
        $browser,
        $assetPanel,
        $projectProperties,
        $searchPanel,
        $gitPanel,
        &$activePanel,
    ): void {
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

        if ($activePanel === 'git') {
            $gitPanel->refresh();
            return;
        }

        $browser->refresh();
    });
$panelHeader = (new Box(Orientation::HORIZONTAL, 4))
    ->cssClass('panel-header-bar')
    ->append($panelTitle, $refreshButton);

$explorerPage = (new ScrolledWindow($browser))->expand();
$searchPage = (new ScrolledWindow($searchPanel))->expand()->visible(false);
$gitPage = (new ScrolledWindow($gitPanel))->expand()->visible(false);
$debugPage = (new ScrolledWindow($debugPanel))->expand()->visible(false);
$assetsPage = (new ScrolledWindow($assetPanel))->expand()->visible(false);
$propertiesPage = (new ScrolledWindow($projectProperties))->expand()->visible(false);

$panelPages = (new Box(Orientation::VERTICAL, 0))
    ->append(
        $explorerPage,
        $searchPage,
        $gitPage,
        $debugPage,
        $assetsPage,
        $propertiesPage,
    )
    ->expand();

$sidePanel = (new Box(Orientation::VERTICAL, 0))
    ->cssClass('side-panel')
    ->append($panelHeader, $panelPages);
$sidePanel->raw()->setSizeRequest(180, -1);

$sidePanelMap = [
    'explorer' => [$explorerPage, $explorerButton, 'EXPLORER'],
    'search' => [$searchPage, $searchButton, 'RECHERCHE'],
    'git' => [$gitPage, $gitButton, 'GIT'],
    'debug' => [$debugPage, $debugActivity, 'DÉBOGAGE'],
    'assets' => [$assetsPage, $assetsButton, 'ASSETS'],
    'properties' => [$propertiesPage, $settingsButton, 'PROPRIÉTÉS'],
];

$showSidePanel = function (string $name) use (
    &$sidePanelMap,
    &$activePanel,
    $panelTitle,
    $searchPanel,
    $assetPanel,
    $projectProperties,
    $gitPanel,
): void {
    if (!isset($sidePanelMap[$name])) {
        return;
    }

    foreach ($sidePanelMap as $key => [$page, $button, $title]) {
        $page->visible($key === $name);

        if ($key === $name) {
            $button->cssClass('active');
        } else {
            $button->removeCssClass('active');
        }
    }

    $panelTitle->text($sidePanelMap[$name][2]);
    $activePanel = $name;

    match ($name) {
        'search' => $searchPanel->focus(),
        'assets' => $assetPanel->refresh(),
        'properties' => $projectProperties->refresh(),
        'git' => $gitPanel->refresh(),
        default => null,
    };
};

$showExplorer = fn () => $showSidePanel('explorer');
$showSearchPanel = fn () => $showSidePanel('search');
$showGitPanel = fn () => $showSidePanel('git');
$showDebugPanel = fn () => $showSidePanel('debug');
$showAssetsPanel = fn () => $showSidePanel('assets');
$showPropertiesPanel = fn () => $showSidePanel('properties');

$explorerButton->onClicked(fn () => $showExplorer());
$searchButton->onClicked(fn () => $showSearchPanel());
$gitButton->onClicked(fn () => $showGitPanel());
$debugActivity->onClicked(fn () => $showDebugPanel());
$assetsButton->onClicked(fn () => $showAssetsPanel());
$designerActivity->onClicked(fn () => $openDesigner());
$settingsButton->onClicked(fn () => $showPropertiesPanel());

// --- Surveillance du projet ------------------------------------------------
$workspaceWatcher = null;

if (is_dir($root)) {
    $workspaceWatcher = new WorkspaceWatcher($root, function (array $paths) use (
        $browser,
        $symbolSearch,
        $gitPanel,
    ): void {
        $browser->refresh();

        foreach ($paths as $path) {
            $symbolSearch->invalidate($path);
        }

        $gitPanel->refresh();
    });

    $window->raw()->onCloseRequest(function () use ($workspaceWatcher, $scrollSync): void {
        $workspaceWatcher->cancel();
        $scrollSync->detach();
    });
}

// --- Onglet, éditeur, terminal --------------------------------------------
$editorArea = (new Box(Orientation::VERTICAL, 0))
    ->append($findBar, $editorSplit)
    ->expand();

$panelProblemsTab = (new Button('PROBLÈMES'))->cssClass('panel-tab');
$panelOutputTab = (new Button('SORTIE'))->cssClass('panel-tab');
$panelDiffTab = (new Button('DIFF'))->cssClass('panel-tab');
$panelTerminalTab = (new Button('TERMINAL'))->cssClass('panel-tab')->cssClass('active');

$panelTabs = (new Box(Orientation::HORIZONTAL, 0))
    ->cssClass('panel-tabs')
    ->append($panelProblemsTab, $panelOutputTab, $panelDiffTab, $panelTerminalTab);

$problemsPage = (new ScrolledWindow($problemsPanel))->expand()->visible(false);
$outputPage = $outputPanel->expand()->visible(false);
$diffPage = $diffPanel->expand()->visible(false);
$terminalPage = $terminal->expand();

$bottomPages = (new Box(Orientation::VERTICAL, 0))
    ->append($problemsPage, $outputPage, $diffPage, $terminalPage)
    ->expand();

$terminalVisible = true;
$terminalPanel = (new Box(Orientation::VERTICAL, 0))
    ->append($panelTabs, $bottomPages)
    ->expand();

$showBottomTab = function (string $tab) use (
    $problemsPage,
    $outputPage,
    $diffPage,
    $terminalPage,
    $panelProblemsTab,
    $panelOutputTab,
    $panelDiffTab,
    $panelTerminalTab,
    $terminalPanel,
    &$terminalVisible,
): void {
    $pages = [
        'problems' => $problemsPage,
        'output' => $outputPage,
        'diff' => $diffPage,
        'terminal' => $terminalPage,
    ];
    $tabs = [
        'problems' => $panelProblemsTab,
        'output' => $panelOutputTab,
        'diff' => $panelDiffTab,
        'terminal' => $panelTerminalTab,
    ];

    foreach ($pages as $name => $page) {
        $page->visible($name === $tab);
    }

    foreach ($tabs as $name => $button) {
        if ($name === $tab) {
            $button->cssClass('active');
        } else {
            $button->removeCssClass('active');
        }
    }

    $terminalPanel->visible(true);
    $terminalVisible = true;
};

$panelProblemsTab->onClicked(fn () => $showBottomTab('problems'));
$panelOutputTab->onClicked(fn () => $showBottomTab('output'));
$panelDiffTab->onClicked(fn () => $showBottomTab('diff'));
$panelTerminalTab->onClicked(fn () => $showBottomTab('terminal'));

$showOutput = function () use ($showBottomTab): void {
    ($showBottomTab)('output');
};

$showDiff = function () use ($showBottomTab): void {
    ($showBottomTab)('diff');
};

$refreshProblemsTab = function () use ($diagnostics, $panelProblemsTab): void {
    $count = $diagnostics->count();
    $panelProblemsTab->label('PROBLÈMES' . ($count > 0 ? ' (' . $count . ')' : ''));
};

$refreshProblemsTab();

$toggleTerminal = function () use ($terminalPanel, &$terminalVisible, $showBottomTab): void {
    $terminalVisible = !$terminalVisible;
    $terminalPanel->visible($terminalVisible);

    if ($terminalVisible) {
        ($showBottomTab)('terminal');
    }
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
$statusBranch = (new Label($gitPanel->branch()))->cssClass('status-item');
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

$commandPalette = new CommandPalette($window, [
    ['label' => 'Fichier : Enregistrer', 'detail' => 'Ctrl+S', 'action' => $save],
    ['label' => 'Fichier : Fermer l\'onglet', 'detail' => 'Ctrl+W', 'action' => $closeTab],
    ['label' => 'Fichier : Onglet suivant', 'detail' => 'Ctrl+Tab', 'action' => fn () => $nextTab(1)],
    ['label' => 'Fichier : Onglet précédent', 'detail' => 'Ctrl+Shift+Tab', 'action' => fn () => $nextTab(-1)],
    ['label' => 'Navigation : Aller au fichier', 'detail' => 'Ctrl+P', 'action' => fn () => $quickOpen->present()],
    ['label' => 'Navigation : rechercher un symbole', 'detail' => 'Ctrl+T', 'action' => fn () => $symbolSearch->present()],
    ['label' => 'Édition : rechercher', 'detail' => 'Ctrl+F', 'action' => fn () => $findBar->show()],
    ['label' => 'Édition : remplacer', 'detail' => 'Ctrl+H', 'action' => fn () => $findBar->showReplace()],
    ['label' => 'Affichage : aperçu Markdown', 'detail' => 'Ctrl+Shift+V', 'action' => fn () => $togglePreview()],
    ['label' => 'Affichage : Explorateur', 'action' => $showExplorer],
    ['label' => 'Affichage : Recherche', 'action' => $showSearchPanel],
    ['label' => 'Affichage : Problèmes', 'action' => fn () => ($showBottomTab)('problems')],
    ['label' => 'Affichage : Sortie', 'action' => fn () => ($showBottomTab)('output')],
    ['label' => 'Affichage : Diff', 'action' => fn () => ($showBottomTab)('diff')],
    ['label' => 'Sortie : vider', 'action' => fn () => $outputPanel->clear()],
    ['label' => 'Affichage : Git', 'action' => fn () => $showGitPanel()],
    ['label' => 'Git : actualiser', 'action' => fn () => $refreshGit()],
    ['label' => 'Git : indexer tout', 'action' => fn () => $gitPanel->stageAll()],
    ['label' => 'Git : afficher le diff', 'action' => fn () => $showGitDiff()],
    ['label' => 'Git : changer de branche', 'action' => function () use ($showGitPanel, $gitPanel): void {
        $showGitPanel();
        $gitPanel->openBranches();
    }],
    ['label' => 'Git : créer une branche', 'action' => function () use ($showGitPanel, $gitPanel): void {
        $showGitPanel();
        $gitPanel->focusBranchEntry();
    }],
    ['label' => 'Git : fusionner une branche', 'action' => function () use ($showGitPanel, $gitPanel): void {
        $showGitPanel();
        $gitPanel->focusMergeEntry();
    }],
    ['label' => 'Affichage : Débogage', 'action' => $showDebugPanel],
    ['label' => 'Affichage : Assets', 'action' => $showAssetsPanel],
    ['label' => 'Affichage : Propriétés du projet', 'action' => $showPropertiesPanel],
    ['label' => 'Affichage : Concepteur d\'interface', 'action' => $openDesigner],
    ['label' => 'Affichage : Terminal', 'action' => $toggleTerminal],
    ['label' => 'Exécuter : fichier courant', 'detail' => 'F5', 'action' => fn () => $run()],
    ['label' => 'Exécuter : compiler en natif', 'action' => $compileNativeFile],
    ['label' => 'Exécuter : déboguer', 'action' => fn () => $debugButton->click()],
    ['label' => 'Exporter : application AppImage', 'action' => fn () => $exportApplication()],
    ['label' => 'Exporter : application Flatpak', 'action' => fn () => $exportApplication(ProjectBuilder::FORMAT_FLATPAK)],
    ['label' => 'Aide : raccourcis clavier', 'action' => fn () => $window->toast(
        'Ctrl+S Enregistrer · Ctrl+P Aller au fichier · Ctrl+Shift+P Commandes · Ctrl+W Fermer · Ctrl+Tab Onglet suivant',
        8000,
    )],
    ['label' => 'Aide : à propos de Cyonima IDE', 'action' => fn () => $window->toast(
        'Cyonima IDE 0.1.0 · MIT · PHP 8.3 + GTK4',
        6000,
    )],
]);

$menuBar = (new MenuBar())
    ->addMenu('Fichier', [
        ['label' => 'Enregistrer', 'shortcut' => 'Ctrl+S', 'action' => $save],
        ['label' => 'Fermer l\'onglet', 'shortcut' => 'Ctrl+W', 'action' => $closeTab],
        ['label' => 'Onglet suivant', 'shortcut' => 'Ctrl+Tab', 'action' => fn () => $nextTab(1)],
        ['label' => 'Onglet précédent', 'shortcut' => 'Ctrl+Shift+Tab', 'action' => fn () => $nextTab(-1)],
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
        ['label' => 'Rechercher…', 'shortcut' => 'Ctrl+F', 'action' => fn () => $findBar->show()],
        ['label' => 'Remplacer…', 'shortcut' => 'Ctrl+H', 'action' => fn () => $findBar->showReplace()],
        ['separator' => true],
        ['label' => 'Tout sélectionner', 'shortcut' => 'Ctrl+A', 'action' => fn () => $editor->selectAll()],
    ])
    ->addMenu('Affichage', [
        ['label' => 'Palette de commandes…', 'shortcut' => 'Ctrl+Shift+P', 'action' => fn () => $commandPalette->present()],
        ['label' => 'Explorateur', 'action' => $showExplorer],
        ['label' => 'Recherche', 'action' => $showSearchPanel],
        ['label' => 'Problèmes', 'action' => fn () => ($showBottomTab)('problems')],
        ['label' => 'Sortie', 'action' => fn () => ($showBottomTab)('output')],
        ['label' => 'Diff', 'action' => fn () => ($showBottomTab)('diff')],
        ['label' => 'Aperçu Markdown', 'shortcut' => 'Ctrl+Shift+V', 'action' => fn () => $togglePreview()],
        ['label' => 'Git', 'action' => fn () => $showGitPanel()],
        ['label' => 'Changer de branche…', 'action' => function () use ($showGitPanel, $gitPanel): void {
            $showGitPanel();
            $gitPanel->openBranches();
        }],
        ['label' => 'Fusionner une branche…', 'action' => function () use ($showGitPanel, $gitPanel): void {
            $showGitPanel();
            $gitPanel->focusMergeEntry();
        }],
        ['label' => 'Aller au fichier…', 'shortcut' => 'Ctrl+P', 'action' => fn () => $quickOpen->present()],
        ['label' => 'Rechercher un symbole…', 'shortcut' => 'Ctrl+T', 'action' => fn () => $symbolSearch->present()],
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
            'Ctrl+S Enregistrer · Ctrl+F Rechercher · Ctrl+H Remplacer · Ctrl+P Fichier · Ctrl+T Symbole · Ctrl+Shift+P Commandes · Ctrl+Shift+V Aperçu Markdown · Ctrl+W Fermer · Ctrl+Tab Onglet suivant · Ctrl+Z Annuler · Ctrl+Y Rétablir · Ctrl+A Tout sélectionner · Ctrl+Espace IntelliSense · F5 Exécuter',
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
$sampleSecond = null;
$sampleMd = null;

if ($auto) {
    // On travaille sur une copie : le scénario automatique sauvegarde.
    $sample = sys_get_temp_dir() . '/cyonima_ide_demo.php';
    $sampleSecond = sys_get_temp_dir() . '/cyonima_ide_demo2.php';
    $sampleMd = sys_get_temp_dir() . '/cyonima_ide_demo.md';
    $source = $root . '/hello.php';

    if (is_file($source) && is_readable($source)) {
        copy($source, $sample);
    } else {
        file_put_contents($sample, "<?php\n\ndeclare(strict_types=1);\n\necho \"Cyonima\\n\";\n");
    }

    file_put_contents($sampleSecond, "<?php\n\necho \"Second onglet\\n\";\n");
    file_put_contents($sampleMd, "# Aperçu\n\n**Markdown** et `code`.\n");

    Loop::after(210, function () use ($diagnostics, $problemsPanel, $refreshProblemsTab, $sample): void {
        $diagnostics->update('file://' . $sample, [
            [
                'range' => ['start' => ['line' => 1, 'character' => 0]],
                'severity' => 2,
                'message' => 'Variable inutilisée',
                'source' => 'demo',
            ],
            [
                'range' => ['start' => ['line' => 2, 'character' => 4]],
                'severity' => 1,
                'message' => 'Accolade manquante',
                'source' => 'demo',
            ],
        ]);
        $problemsPanel->set($diagnostics->all());
        $refreshProblemsTab();
    });
    Loop::after(215, fn () => ($showGitPanel)());
    Loop::after(225, fn () => ($showExplorer)());
    Loop::after(230, fn () => ($showBottomTab)('problems'));
    Loop::after(235, function () use ($diffPanel): void {
        $diffPanel->show('demo.php', "--- a/demo.php\n+++ b/demo.php\n@@ -1 +1 @@\n-old\n+new");
    });
    Loop::after(240, fn () => ($showBottomTab)('diff'));
    Loop::after(250, function () use ($outputPanel): void {
        $outputPanel->append('Sortie de démonstration', 'Auto');
        $outputPanel->append("Ligne 2\nLigne 3", 'Auto');
    });
    Loop::after(270, fn () => ($showBottomTab)('output'));
    Loop::after(290, fn () => ($showBottomTab)('terminal'));

    Loop::after(180, function () use ($symbolSearch): void {
        $symbolSearch->present();
        $symbolSearch->setQuery('hello');
        $symbolSearch->dismiss();
    });
    Loop::after(200, function () use ($quickOpen): void {
        $quickOpen->present();
        $quickOpen->setQuery('hello');
        $quickOpen->chooseCurrent();
    });
    Loop::after(240, fn () => $commandPalette->present());
    Loop::after(280, fn () => $commandPalette->dismiss());
    Loop::after(260, fn () => $closeDocument(realpath($root . '/hello.php') ?: $root . '/hello.php'));
    Loop::after(300, fn () => $openFile($sample));
    Loop::after(380, fn () => $openFile($sampleSecond));
    Loop::after(450, fn () => $openFile($sample));
    Loop::after(500, fn () => $runButton->click());
    Loop::after(620, function () use ($findBar): void {
        $findBar->show();
        $findBar->setQuery('terminal');
        $findBar->findNext();
    });
    Loop::after(660, fn () => $findBar->hide());
    Loop::after(700, function () use ($editor): void {
        $editor->setText($editor->text() . "\nstr");
        $editor->moveCursorToEnd();
    });
    Loop::after(950, fn () => $complete(false));
    Loop::after(1250, fn () => $popup->chooseCurrent());
    Loop::after(1600, fn () => $save());
    Loop::after(1750, fn () => $closeDocument(realpath($sampleSecond) ?: $sampleSecond));
    Loop::after(1800, fn () => $openFile($sampleMd));
    Loop::after(1820, fn () => $togglePreview());
    Loop::after(1840, fn () => $togglePreview());
    Loop::after(1860, fn () => $openFile($sample));
    Loop::after(1950, fn () => $window->raw()->close());
}

$app->run();

$lsp?->stop();
$debug?->close();

foreach ([$sample, $sampleSecond, $sampleMd] as $temporary) {
    if ($temporary !== null && is_file($temporary)) {
        @unlink($temporary);
    }
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
