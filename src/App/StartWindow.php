<?php

declare(strict_types=1);

namespace Cyonima\App;

use Cyonima\Gtk\Raw\Orientation;
use Cyonima\Runtime\AdwApplication;
use Cyonima\Runtime\Loop;
use Cyonima\Runtime\ProjectFactory;
use Cyonima\Runtime\RecentProjects;
use Cyonima\UI\AdwWindow;
use Cyonima\UI\Box;
use Cyonima\UI\Button;
use Cyonima\UI\Clamp;
use Cyonima\UI\Entry;
use Cyonima\UI\IconButton;
use Cyonima\UI\Label;
use Cyonima\UI\ScrolledWindow;
use Cyonima\UI\Theme;
use Cyonima\UI\Widget;

/**
 * Fenêtre de démarrage façon Visual Studio.
 *
 * Elle choisit un modèle, crée un environnement dédié (code, assets,
 * manifeste, licence du projet) puis lance l'IDE dans un processus séparé sur
 * ce projet.
 *
 * Cyonima est distribué sous licence MIT : il n'y a ni clé d'activation ni
 * licence payante. Seule la licence du projet créé est choisie ici.
 */
final class StartWindow
{
    private AdwApplication $app;
    private AdwWindow $window;
    private RecentProjects $recents;
    private string $home;
    private string $template = ProjectFactory::TEMPLATE_GTK;
    private string $projectLicense = 'MIT';
    private bool $appIdTouched = false;

    /** @var array<string, Button> */
    private array $templateCards = [];

    /** @var array<string, Button> */
    private array $licenseChoices = [];

    /** @var array<string, Button> */
    private array $navButtons = [];

    /** @var array<string, Box> */
    private array $pages = [];

    /** @var list<Widget> */
    private array $recentRows = [];

    /** @var list<Widget> */
    private array $openRecentRows = [];

    private Box $recentBox;
    private Box $openRecentBox;
    private Label $recentEmpty;
    private Label $openRecentEmpty;
    private Label $preview;
    private Label $formMessage;
    private Label $openMessage;
    private Label $licenseNote;
    private Label $sidebarFooter;
    private Entry $nameEntry;
    private Entry $locationEntry;
    private Entry $appIdEntry;
    private Entry $assetEntry;
    private Entry $openEntry;
    private Button $createButton;
    private Button $openButton;
    private IconButton $clearButton;

    public function __construct(?string $home = null)
    {
        $this->home = rtrim($home ?? dirname(__DIR__, 2), '/');
        $this->recents = new RecentProjects();
        $this->app = new AdwApplication('com.cyonima.start');
        $this->window = $this->app->window('Cyonima', 'Démarrer');
        $this->window->raw()->setDefaultSize(1180, 840);
        $this->window->raw()->setSizeRequest(960, 680);

        Theme::apply($this->window);
        $this->build();
    }

    public static function run(?string $home = null): void
    {
        (new self($home))->start();
    }

    public function start(): void
    {
        $this->window->present();
        $this->app->run();
    }

    // --- Construction de l'interface ---------------------------------------

    private function build(): void
    {
        $sidebar = $this->sidebar();

        $newPage = $this->page(
            'Créer un projet',
            'Choisissez un modèle : Cyonima prépare le code, les assets, le manifeste et la licence.',
            $this->newProjectBody(),
            true,
        );

        $openPage = $this->page(
            'Ouvrir un dossier',
            'Ouvrez un projet existant. Le manifeste .cyonima/project.json est détecté automatiquement.',
            $this->openFolderBody(),
            false,
        );

        $this->pages = ['new' => $newPage, 'open' => $openPage];

        $pages = (new Box(Orientation::VERTICAL, 0))->expand();
        $pages->append($newPage, $openPage);

        $root = (new Box(Orientation::HORIZONTAL, 0))
            ->expand()
            ->append($sidebar, $pages);

        $this->window->content($root);

        $this->refreshRecent();
        $this->showPage('new');
    }

    private function sidebar(): Box
    {
        $sidebar = (new Box(Orientation::VERTICAL, 0))
            ->cssClass('start-sidebar')
            ->expand(false, true);
        $sidebar->raw()->setSizeRequest(292, -1);

        $newButton = $this->navButton('Nouveau projet', 'new');
        $openButton = $this->navButton('Ouvrir un dossier', 'open');

        $this->clearButton = (new IconButton('edit-clear-symbolic', 'Effacer les projets récents'))
            ->onClicked(function (): void {
                $this->recents->clear();
                $this->refreshRecent();
                $this->window->toast('Projets récents effacés');
            });

        $recentHeader = (new Box(Orientation::HORIZONTAL, 4))
            ->append(
                (new Label('PROJETS RÉCENTS'))->cssClass('start-section')->expand(true, false),
                $this->clearButton,
            );

        $this->recentEmpty = (new Label('Aucun projet récent.'))
            ->cssClass('start-hint')
            ->xalign(0.0)
            ->margin(8);

        $this->recentBox = (new Box(Orientation::VERTICAL, 1))
            ->append($this->recentEmpty);

        $recentScroll = (new ScrolledWindow($this->recentBox))->expand();

        $this->sidebarFooter = (new Label('Cyonima 0.1.0 · MIT · open source'))
            ->cssClass('start-footer');

        $sidebar->append(
            (new Label('CYONIMA'))->cssClass('start-brand'),
            (new Label('IDE PHP · GTK4'))->cssClass('start-brand-sub'),
            (new Label('DÉMARRER'))->cssClass('start-section'),
            $newButton,
            $openButton,
            $recentHeader,
            $recentScroll,
            (new Box(Orientation::VERTICAL, 0))->expand(false, true),
            $this->sidebarFooter,
        );

        return $sidebar;
    }

    private function navButton(string $text, string $page): Button
    {
        $button = (new Button())
            ->cssClass('start-nav-button')
            ->child((new Label($text))->xalign(0.0)->expand(true, false))
            ->expand(true, false)
            ->onClicked(fn () => $this->showPage($page));

        $this->navButtons[$page] = $button;

        return $button;
    }

    private function page(string $title, string $subtitle, Widget $body, bool $visible): Box
    {
        $content = (new Box(Orientation::VERTICAL, 14))
            ->cssClass('start-content')
            ->append(
                (new Label($title))->cssClass('start-title')->xalign(0.0),
                (new Label($subtitle))->cssClass('start-subtitle')->wrap(true)->xalign(0.0),
                $body,
            );

        $scroll = (new ScrolledWindow(new Clamp($content, 860)))->expand();

        $page = (new Box(Orientation::VERTICAL, 0))
            ->cssClass('start-page')
            ->expand()
            ->append($scroll);

        return $page->visible($visible);
    }

    private function newProjectBody(): Box
    {
        $body = (new Box(Orientation::VERTICAL, 14))->expand(true, false);

        $templates = ProjectFactory::templates();
        $cards = (new Box(Orientation::HORIZONTAL, 10))->homogeneous();

        foreach ($templates as $key => $info) {
            $card = (new Button())
                ->cssClass('start-card')
                ->child(
                    (new Box(Orientation::VERTICAL, 4))
                        ->append(
                            (new Label($info['name']))->cssClass('start-card-title')->xalign(0.0),
                            (new Label($info['description']))->cssClass('start-card-desc')->wrap(true),
                        ),
                )
                ->expand(true, false)
                ->onClicked(fn () => $this->selectTemplate($key));

            $this->templateCards[$key] = $card;
            $cards->append($card);
        }

        $this->nameEntry = (new Entry('Mon application'))->cssClass('start-entry')->expand(true, false);
        $this->locationEntry = (new Entry(ProjectFactory::defaultLocation(), ProjectFactory::defaultLocation()))
            ->cssClass('start-entry')
            ->expand(true, false);
        $this->appIdEntry = (new Entry('com.exemple.monapplication'))->cssClass('start-entry')->expand(true, false);
        $this->assetEntry = (new Entry('/chemin/vers/logo.png'))
            ->cssClass('start-entry')
            ->expand(true, false);

        $this->nameEntry->onChanged(function (): void {
            $suggestion = $this->suggestedAppId();
            if (!$this->appIdTouched && $this->appIdEntry->text() !== $suggestion) {
                $this->appIdEntry->setText($suggestion);
            }
            $this->updatePreview();
        });

        $this->locationEntry->onChanged(fn () => $this->updatePreview());

        $this->appIdEntry->onChanged(function (): void {
            $this->appIdTouched = $this->appIdEntry->text() !== $this->suggestedAppId();
            $this->updatePreview();
        });

        $this->createButton = (new Button('Créer le projet'))
            ->cssClass('start-primary')
            ->onClicked(fn () => $this->createProject());

        $this->nameEntry->onActivate(fn () => $this->createProject());
        $this->appIdEntry->onActivate(fn () => $this->createProject());

        $licenseChoices = (new Box(Orientation::HORIZONTAL, 6))->homogeneous();
        foreach (ProjectFactory::licenses() as $spdx => $name) {
            $choice = (new Button($spdx === 'Proprietary' ? 'Propriétaire' : $spdx))
                ->cssClass('start-choice')
                ->expand(true, false)
                ->onClicked(fn () => $this->selectProjectLicense($spdx));

            $this->licenseChoices[$spdx] = $choice;
            $licenseChoices->append($choice);
        }

        $assets = (new Box(Orientation::HORIZONTAL, 6))->append(
            (new Label('assets/icon.svg'))->cssClass('start-asset'),
            (new Label('assets/theme.css'))->cssClass('start-asset'),
            (new Label('LICENSE'))->cssClass('start-asset'),
        );

        $this->preview = (new Label(''))->cssClass('start-hint')->wrap(true)->xalign(0.0);
        $this->formMessage = (new Label(''))->cssClass('start-error')->wrap(true)->xalign(0.0)->visible(false);
        $this->licenseNote = (new Label(''))->cssClass('start-hint');

        $actions = (new Box(Orientation::HORIZONTAL, 12))
            ->append($this->createButton, $this->licenseNote);

        $body->append(
            (new Label('MODÈLES'))->cssClass('start-section')->xalign(0.0),
            $cards,
            (new Label('PROJET'))->cssClass('start-section')->xalign(0.0),
            (new Box(Orientation::HORIZONTAL, 12))->homogeneous()->append(
                $this->field('Nom du projet', $this->nameEntry, 'Dossier créé à partir du nom.')->expand(true, false),
                $this->field('Emplacement', $this->locationEntry, 'Sous-dossier de cet emplacement.')->expand(true, false),
            ),
            (new Box(Orientation::HORIZONTAL, 12))->homogeneous()->append(
                $this->field('Identifiant d\'application', $this->appIdEntry, 'Pour GTK et Flatpak.')->expand(true, false),
                $this->field('Asset supplémentaire', $this->assetEntry, 'Chemin absolu copié dans assets/ (facultatif).')->expand(true, false),
            ),
            $this->field('Licence du projet', $licenseChoices),
            (new Label('ASSETS INCLUS'))->cssClass('start-section')->xalign(0.0),
            $assets,
            $this->preview,
            $this->formMessage,
            $actions,
        );

        $this->selectTemplate($this->template);
        $this->selectProjectLicense($this->projectLicense);
        $this->updatePreview();

        return $body;
    }

    private function openFolderBody(): Box
    {
        $body = (new Box(Orientation::VERTICAL, 14))->expand(true, false);

        $this->openEntry = (new Entry('/chemin/du/projet'))->cssClass('start-entry')->expand(true, false);
        $this->openEntry->onActivate(fn () => $this->openFolder());

        $this->openButton = (new Button('Ouvrir'))
            ->cssClass('start-primary')
            ->onClicked(fn () => $this->openFolder());

        $this->openMessage = (new Label(''))->cssClass('start-error')->wrap(true)->xalign(0.0)->visible(false);

        $this->openRecentEmpty = (new Label('Aucun projet récent.'))
            ->cssClass('start-hint')
            ->xalign(0.0);

        $this->openRecentBox = (new Box(Orientation::VERTICAL, 2))
            ->append($this->openRecentEmpty);

        $body->append(
            (new Label('DOSSIER'))->cssClass('start-section')->xalign(0.0),
            $this->field('Chemin du projet', $this->openEntry, 'Un projet Cyonima contient .cyonima/project.json ; un dossier simple est accepté.'),
            (new Box(Orientation::HORIZONTAL, 10))->append($this->openButton),
            $this->openMessage,
            (new Label('PROJETS RÉCENTS'))->cssClass('start-section')->xalign(0.0),
            $this->openRecentBox,
        );

        return $body;
    }

    private function field(string $label, Widget $input, string $hint = ''): Box
    {
        $box = (new Box(Orientation::VERTICAL, 4))
            ->append(
                (new Label($label))->cssClass('start-field-label')->xalign(0.0),
                $input,
            );

        if ($hint !== '') {
            $box->append((new Label($hint))->cssClass('start-hint')->xalign(0.0)->wrap(true));
        }

        return $box;
    }

    // --- Navigation et état -------------------------------------------------

    private function showPage(string $page): void
    {
        foreach ($this->pages as $key => $widget) {
            $widget->visible($key === $page);
        }

        foreach ($this->navButtons as $key => $button) {
            if ($key === $page) {
                $button->cssClass('active');
            } else {
                $button->removeCssClass('active');
            }
        }

        $this->window->subtitle(match ($page) {
            'open' => 'Ouvrir un dossier',
            default => 'Nouveau projet',
        });
    }

    private function selectTemplate(string $template): void
    {
        $this->template = $template;

        foreach ($this->templateCards as $key => $card) {
            if ($key === $template) {
                $card->cssClass('selected');
            } else {
                $card->removeCssClass('selected');
            }
        }

        $this->updatePreview();
    }

    private function selectProjectLicense(string $license): void
    {
        $this->projectLicense = $license;

        foreach ($this->licenseChoices as $key => $choice) {
            if ($key === $license) {
                $choice->cssClass('selected');
            } else {
                $choice->removeCssClass('selected');
            }
        }

        $licenses = ProjectFactory::licenses();
        $this->licenseNote->text('Licence du projet : ' . ($licenses[$license] ?? $license));
        $this->updatePreview();
    }

    private function updatePreview(): void
    {
        if (!isset($this->preview, $this->nameEntry, $this->locationEntry)) {
            return;
        }

        $name = trim($this->nameEntry->text());
        $location = trim($this->locationEntry->text());
        $template = ProjectFactory::templates()[$this->template]['name'] ?? $this->template;
        $license = ProjectFactory::licenses()[$this->projectLicense] ?? $this->projectLicense;

        if ($name === '') {
            $this->preview->text(sprintf(
                'Emplacement : %s · Modèle : %s · Licence : %s',
                $location === '' ? ProjectFactory::defaultLocation() : $location,
                $template,
                $license,
            ));
            return;
        }

        $target = rtrim($location === '' ? ProjectFactory::defaultLocation() : $location, '/')
            . '/' . ProjectFactory::slug($name);

        $this->preview->text(sprintf(
            'Dossier : %s · Modèle : %s · Licence : %s · 2 assets',
            $target,
            $template,
            $license,
        ));
    }

    private function suggestedAppId(): string
    {
        return ProjectFactory::suggestAppId($this->nameEntry->text());
    }

    private function refreshRecent(): void
    {
        foreach ($this->recentRows as $row) {
            $this->recentBox->remove($row);
        }
        foreach ($this->openRecentRows as $row) {
            $this->openRecentBox->remove($row);
        }

        $this->recentRows = [];
        $this->openRecentRows = [];

        $entries = $this->recents->all();

        $this->recentEmpty->visible($entries === []);
        $this->openRecentEmpty->visible($entries === []);

        foreach ($entries as $entry) {
            $sidebarRow = $this->recentButton($entry);
            $this->recentBox->append($sidebarRow);
            $this->recentRows[] = $sidebarRow;

            $openRow = $this->recentButton($entry, true);
            $this->openRecentBox->append($openRow);
            $this->openRecentRows[] = $openRow;
        }
    }

    /** @param array{path: string, name: string, template: string, openedAt: string, exists: bool} $entry */
    private function recentButton(array $entry, bool $large = false): Button
    {
        $name = (new Label($entry['name']))->cssClass('start-recent-name')->xalign(0.0);
        $path = (new Label($this->shortPath($entry['path'], $large ? 64 : 34)))
            ->cssClass('start-recent-path')
            ->xalign(0.0);

        if (!$entry['exists']) {
            $name->cssClass('start-recent-missing');
            $path->text('Dossier introuvable');
        }

        $content = (new Box(Orientation::VERTICAL, 1))
            ->append($name, $path)
            ->expand(true, false);

        return (new Button())
            ->cssClass('start-recent-item')
            ->child($content)
            ->expand(true, false)
            ->onClicked(fn () => $this->openRecent($entry));
    }

    private function shortPath(string $path, int $max): string
    {
        $home = getenv('HOME');
        if ($home !== false && $home !== '' && str_starts_with($path, rtrim($home, '/'))) {
            $path = '~' . substr($path, strlen(rtrim($home, '/')));
        }

        if (strlen($path) <= $max) {
            return $path;
        }

        return '…' . substr($path, -($max - 1));
    }

    /** @param array{path: string, name: string, template: string, openedAt: string, exists: bool} $entry */
    private function openRecent(array $entry): void
    {
        if (!$entry['exists']) {
            $this->openEntry->setText($entry['path']);
            $this->showPage('open');
            $this->showOpenMessage('Dossier introuvable : ' . $entry['path']);
            return;
        }

        $this->launch($entry['path']);
    }

    // --- Actions ------------------------------------------------------------

    private function createProject(): void
    {
        $extra = trim($this->assetEntry->text());
        $extraAssets = $extra === '' ? [] : [$extra];

        $result = ProjectFactory::create(
            $this->nameEntry->text(),
            $this->locationEntry->text(),
            $this->template,
            $this->projectLicense,
            $this->appIdEntry->text(),
            $extraAssets,
            $this->home,
        );

        if (!$result['ok'] || $result['path'] === null) {
            $this->showFormMessage($result['message'], false);
            return;
        }

        $manifest = $result['manifest'] ?? [];
        $this->recents->add(
            $result['path'],
            is_string($manifest['name'] ?? null) ? $manifest['name'] : null,
            is_string($manifest['template'] ?? null) ? $manifest['template'] : $this->template,
        );
        $this->refreshRecent();

        $this->showFormMessage($result['message'], true);
        $this->launch($result['path'], $result['warnings']);
    }

    private function openFolder(): void
    {
        $raw = trim($this->openEntry->text());

        if ($raw === '') {
            $this->showOpenMessage('Indiquez un dossier à ouvrir.');
            return;
        }

        if (str_starts_with($raw, '~')) {
            $home = getenv('HOME');
            $raw = ($home === false ? '' : rtrim($home, '/')) . substr($raw, 1);
        }

        if (!str_starts_with($raw, '/')) {
            $raw = getcwd() . '/' . $raw;
        }

        if (is_file($raw)) {
            $raw = dirname($raw);
        }

        $path = realpath($raw);
        if ($path === false || !is_dir($path)) {
            $this->showOpenMessage('Dossier introuvable : ' . $raw);
            return;
        }

        $manifest = ProjectFactory::manifest($path);
        $this->recents->add(
            $path,
            is_string($manifest['name'] ?? null) ? $manifest['name'] : null,
            is_string($manifest['template'] ?? null) ? $manifest['template'] : 'folder',
        );
        $this->refreshRecent();
        $this->launch($path);
    }

    private function launch(string $path, array $warnings = []): void
    {
        if (!is_dir($path)) {
            $this->showOpenMessage('Dossier introuvable : ' . $path);
            return;
        }

        $command = [
            PHP_BINARY,
            '-d',
            'ffi.enable=1',
            $this->home . '/bin/cyonima.php',
            '--project=' . $path,
        ];

        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment['CYONIMA_HOME'] = $this->home;

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => STDOUT,
            2 => STDERR,
        ];

        $process = @proc_open($command, $descriptors, $pipes, $this->home, $environment);
        if (!is_resource($process)) {
            $this->showFormMessage('Impossible de lancer l\'IDE.', false);
            return;
        }

        foreach ($warnings as $warning) {
            fwrite(STDERR, '[cyonima] ' . $warning . "\n");
        }

        $this->window->toast('Ouverture de ' . ProjectFactory::projectName($path));
        Loop::after(120, fn () => $this->app->quit());
    }

    // --- Messages -----------------------------------------------------------

    private function showFormMessage(string $message, bool $success): void
    {
        $this->formMessage
            ->removeCssClass('start-error')
            ->removeCssClass('start-success')
            ->cssClass($success ? 'start-success' : 'start-error')
            ->text($message)
            ->visible(true);
    }

    private function showOpenMessage(string $message): void
    {
        $this->openMessage->text($message)->visible(true);
    }
}
