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
| Panneau latéral | `EXPLORER` (fichiers) ou `DÉBOGAGE` (pile, variables) |
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
