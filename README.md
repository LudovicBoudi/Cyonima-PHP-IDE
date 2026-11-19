# Cyonima-PHP-IDE

Un IDE pour PHP 8 et un compilateur d'applications desktop natives.

Deux axes, un même socle :

- **Le runtime** : des applications PHP avec une UI **native GTK4** (pas de
  WebView, pas de serveur HTTP), empaquetées en exécutables Linux autonomes.
- **L'IDE** : un environnement de développement PHP écrit lui-même en PHP, avec
  des widgets natifs, un éditeur GtkSourceView et un terminal VTE.

## État du projet

| Jalon | État |
|---|---|
| M0 — Spike de faisabilité | ✅ terminé |
| M1 — Générateur de bindings | 🟡 tranche verticale livrée (Gtk Window/Box/Label/Button/CssProvider/StyleContext) |
| M2 — Runtime + façade OO | 🟡 façade minimale et boucle d'événements en place |

Prérequis : PHP 8.3+ avec l'extension FFI, GTK4 (runtime), un serveur X/Wayland.

## Démarrage

```bash
composer install

# Génère les bindings depuis les .typelib (aucun paquet -dev requis)
php -d ffi.enable=true bin/cyonima-gen generate

# Fenêtre GTK4 réelle pilotée par PHP (--auto = scénario scripté)
php -d ffi.enable=true examples/counter.php --auto

# Tests
php -d ffi.enable=true tests/run.php
```

Exemple :

```php
use Cyonima\Runtime\Loop;
use Cyonima\UI\{Box, Button, Label, Window};

$count = 0;
$label = new Label('0');
$button = (new Button('Incrémenter'))
    ->onClicked(function () use (&$count, $label) {
        $count++;
        $label->text((string) $count);
    });

$window = new Window('Démo', 420, 220);
$window->setChild(new Box(spacing: 12)->append($label, $button))
       ->onCloseRequest(fn () => Loop::quit())
       ->present();

Loop::run();
```

## Architecture

```
app PHP  →  Cyonima\UI       façade typée, écrite à la main
         →  Cyonima\Gtk\Raw  bindings générés depuis les .typelib
         →  FFI              un seul cdef, un seul dlopen
         →  GTK4 · Adw · GtkSourceView · VTE
```

Le typelib est la source de vérité : une méthode absente de l'API fait échouer
la génération, et un test différentiel contre PyGObject vérifie l'interprétation.

## Documents

- [`docs/etude-faisabilite.md`](docs/etude-faisabilite.md) — mesures, risques, plan
- [`docs/decisions.md`](docs/decisions.md) — ADR et règles techniques (R1/R2)
- [`docs/generateur.md`](docs/generateur.md) — fonctionnement du générateur
- [`spike/`](spike/README.md) — POC de faisabilité et mesures de fuite

## Décisions actées

| # | Décision |
|---|---|
| ADR-001 | Stack GNOME complet : GTK4 + libadwaita + GtkSourceView5 + VTE |
| ADR-002 | Bindings bruts générés (`Cyonima\Gtk\Raw`) + façade OO (`Cyonima\UI`) |
| ADR-003 | UI impérative retained-mode, sans moteur de re-render |
| ADR-004 | PHP 8.3 minimum |
| ADR-005 | Flatpak pour l'IDE, AppImage avec GTK bundlé pour les apps produites |
