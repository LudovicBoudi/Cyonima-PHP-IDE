# Design — inspiration Visual Studio

L'interface reprend la disposition et la palette de Visual Studio (thème sombre),
sans dépendre d'Adwaita pour le chrome.

## Disposition

```
┌ menus ·················· Cyonima ················· actions ┐
│ ┌──┐ ┌──────────────┐ ┌──────────────────────────────────┐ │
│ │  │ │ EXPLORER     │ │ onglet                           │ │
│ │  │ │              │ ├──────────────────────────────────┤ │
│ │  │ │  adw.php     │ │                                  │ │
│ │  │ │  async.php   │ │           éditeur                │ │
│ │  │ │  counter.php │ │                                  │ │
│ │  │ │  hello.php   │ │                                  │ │
│ │  │ ├──────────────┤ ├──────────────────────────────────┤ │
│ │  │ │ Pile         │ │ PROBLÈMES SORTIE TERMINAL        │ │
│ │  │ │ Variables    │ │ terminal VTE                     │ │
│ └──┘ └──────────────┘ └──────────────────────────────────┘ │
├────────────────────────────────────────────────────────────┤
│ main              Ln 1, Col 1  Espaces : 4  UTF-8  PHP 8.3 │
└────────────────────────────────────────────────────────────┘
```

Composants :

| Zone | Implémentation |
|---|---|
| Barre de menus | `AdwHeaderBar` + labels (`headerStart`) |
| Actions | `IconButton` (Enregistrer, Déboguer, Exécuter) |
| Barre d'activité | `Box` vertical de 48 px, icônes symboliques, indicateur bleu |
| Panneau latéral | `EXPLORER`, `DÉBOGAGE`, `ASSETS` ou `PROPRIÉTÉS` |
| Onglet | nom du fichier, point de modification, fermeture |
| Éditeur | GtkSourceView + schéma `vs-dark` |
| Panneau bas | onglets PROBLÈMES / SORTIE / TERMINAL + VTE |
| Barre de statut | bleue `#007acc`, position, encodage, version PHP |

## Palette

| Rôle | Couleur |
|---|---|
| Fond éditeur | `#1e1e1e` |
| Chrome / barre de titre | `#2d2d30` |
| Panneau latéral | `#252526` |
| Barre d'activité | `#333333` |
| Bordures | `#3f3f46` |
| Texte | `#cccccc` |
| Texte atténué | `#969696` |
| Accent | `#007acc` |
| Sélection de liste | `#094771` |
| Survol de liste | `#2a2d2e` |

## Explorateur redimensionnable

La zone latérale et l'éditeur sont dans un `GtkPaned` horizontal (position
initiale 270 px, largeur minimale 180 px). `resize_start_child=false` : quand on
redimensionne la fenêtre, c'est l'éditeur qui grandit, pas l'explorateur. Le
séparateur se glisse à la souris.

## Fenêtre de démarrage

![Fenêtre de démarrage](screenshot-start.png)

Le lancement sans argument affiche un choix de modèles à gauche et un formulaire
à droite, comme la fenêtre de création de Visual Studio. Les projets récents
sont listés dans la barre latérale et ouvrables en un clic.

La création écrit un environnement complet (`.cyonima/project.json`, `src/`,
`assets/`, `LICENSE`, `README.md`) puis lance l'IDE dans un processus séparé avec
`--project`. La fenêtre de démarrage se ferme : chaque projet a son propre
environnement.

## Licences des projets

Le formulaire de création choisit la licence du projet : MIT, Apache-2.0,
GPL-3.0 ou propriétaire. Le texte est écrit dans `LICENSE` et le manifeste
enregistre l'identifiant SPDX. Cyonima reste sous licence MIT, sans activation
ni fonctionnalité payante.

La vue **PROPRIÉTÉS** (icône engrenage de la barre d'activité) affiche nom,
chemin, modèle, identifiant, version et nombre d'assets. Elle permet de changer
la licence après création : `LICENSE` et le manifeste sont réécrits, puis la
barre de statut est actualisée.

![Propriétés du projet](screenshot-properties.png)

## Assets

![Panneau Assets](screenshot-assets.png)

Troisième vue du panneau latéral : elle liste `assets/` avec type et taille,
importe un fichier (chemin relatif au projet ou absolu), rafraîchit le manifeste
et ouvre l'asset dans l'éditeur. L'icône SVG et le thème CSS générés servent
d'exemple ; `assets/theme.css` est chargé par l'application GTK produite.

## IntelliSense

![Popup IntelliSense](screenshot-intellisense.png)

Après filtrage local (préfixe `strl`), la liste ne garde que `strlen` et se
redimensionne :

![Popup IntelliSense filtré](screenshot-intellisense-filter.png)

- déclenchement automatique **180 ms** après une frappe quand le préfixe fait
  au moins 2 caractères (ou après `.`/`>` quand un serveur LSP est actif) ;
- `Ctrl+Espace` force la liste ;
- popup **ancré au curseur** : `gtk_text_view_get_cursor_locations()` +
  `gtk_popover_set_pointing_to()` ;
- **sans LSP** : suggestions PHP intégrées (`PhpIntellisense`) — mots-clés et
  fonctions avec signatures ;
- **avec LSP** : résultats du serveur, repli local si la réponse est vide ;
- **filtrage local pendant la frappe** : tant que le préfixe s'allonge, la
  liste est filtrée sans nouvelle requête. Le popover est masqué pendant la
  frappe (l'éditeur garde le focus), puis réaffiché **90 ms** après la dernière
  touche avec la taille correcte ;
- une nouvelle requête n'est faite que si le préfixe est raccourci, change de
  mot, ou si la liste est fermée ;
- après insertion, une fenêtre de suppression de 400 ms évite le
  redéclenchement (le buffer émet deux signaux `changed`).

## Schéma d'éditeur

`src/UI/Theme.php` embarque un schéma GtkSourceView `vs-dark` (XML) et l'installe
au démarrage :

1. écriture dans `$TMPDIR/cyonima-styles/vs-dark.xml` ;
2. `append_search_path()` puis `force_rescan()` sur le gestionnaire de schémas ;
3. repli sur `Adwaita-dark` si le chargement échoue.

Les couleurs suivent VS Dark : mots-clés `#569cd6`, chaînes `#ce9178`,
commentaires `#6a9955`, types `#4ec9b0`, fonctions `#dcdcaa`, variables
`#9cdcfe`.

## Personnalisation

Tout le chrome est dans la constante `Theme::CSS` : classes `.activity-bar`,
`.side-panel`, `.panel-header`, `.tab`, `.statusbar`, `.menu-item`. Modifier la
constante suffit à changer le thème, aucune ressource externe n'est requise.

## Écarts assumés avec Visual Studio

- les menus (Fichier, Édition…) sont décoratifs pour l'instant ;
- la barre de titre garde les boutons de fenêtre GNOME, simplement aplatis ;
- pas encore de panneau Recherche ni de gestion de solutions ;
- les couleurs du terminal VTE viennent du thème système, pas du CSS.
