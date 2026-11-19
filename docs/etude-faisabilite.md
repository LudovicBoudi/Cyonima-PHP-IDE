# Étude de faisabilité — UI desktop native en PHP 8 (voie GTK4 + FFI)

> Statut : hypothèse validée par l'expérience le 19/11/2026 sur Ubuntu 24.04,
> PHP 8.3.6, GTK 4.14.5. Voir `spike/` pour le code exécutable.

## 1. Verdict

**Oui, c'est réalisable.** Une application desktop à widgets natifs, écrite
entièrement en PHP 8, sans WebView, sans serveur HTTP et sans couche C
obligatoire, fonctionne dès aujourd'hui. Le POC ouvre une vraie fenêtre GTK4,
réagit aux clics, lit de la saisie, crée des widgets dynamiquement et applique
du CSS — le tout piloté par du code PHP.

Ce n'est pas un raccourci magique : le coût n'est pas dans le runtime, il est
dans **la couche de bindings** et dans **la discipline de gestion mémoire**.

## 2. Ce qui a été mesuré

| Test | Résultat |
|---|---|
| `gtk_init_check()` depuis PHP/FFI | OK (`int(1)`), display X11 détecté |
| Fenêtre + boucle `g_main_loop` + fermeture propre | OK, 2,5 s de boucle |
| Closure PHP branchée sur signal `clicked` | OK, handler PHP appelé par GTK |
| Émission de signal depuis PHP (`g_signal_emit_by_name`) | OK (tests automatisables) |
| Saisie texte `GtkEntry` → PHP | OK, texte UTF-8 récupéré |
| 53 widgets créés + 50 supprimés en un callback | OK, zéro warning, pic 2,0 Mo |
| CSS complet depuis une chaîne PHP | OK (couleurs, radii, padding, typo) |
| Démarrage PHP + FFI + init GTK | **20 ms** |
| Coût d'un appel FFI | **0,06 µs** |

Ces deux derniers chiffres sont l'argument massue face à Electron/WebView :
on est un ordre de grandeur en dessous en latence d'appel, et deux ordres en
dessous en temps de démarrage.

## 3. Le piège qui décide de l'architecture

Le FFI ne connaît **pas** les invariants de GObject. Exemple rencontré pendant
le spike : `gtk_list_box_append(list, label)` emballe le widget dans un
`GtkListBoxRow` implicite. `gtk_list_box_remove(list, label)` échoue alors
silencieusement (`Tried to remove non-child`). Il faut passer la *row*.

Conséquences directes :

1. **Interdiction de binder à la main.** Il faut générer les bindings depuis les
   fichiers `.gir` (GObject Introspection), qui décrivent types, ownership,
   transferts de référence, direction des paramètres et signatures de signaux.
   C'est exactement ce que fait PyGObject pour Python.
2. **Il faut une couche de validation** au-dessus des pointeurs bruts : vérifier
   qu'un `GtkWidget*` est bien un widget, qu'un widget est parenté, qu'un objet
   n'est pas déjà libéré. Sinon, l'erreur devient un segfault.
3. **Les exceptions ne traversent pas la frontière C.** `Throwing from FFI
   callbacks is not allowed`. Tout handler doit encapsuler ses erreurs et les
   remonter après le retour dans PHP.

### Contrainte mesurée : la fuite est par enregistrement, pas par appel

La doc PHP avertit que les callbacks FFI « fuient » et sont « inutilisables
pour des applications longue durée ». Mesures sur ce projet :

| Scénario | Volume | Delta RSS |
|---|---|---|
| Signal GTK, handler connecté une fois | 300 000 appels | +1,75 Mo puis plateau |
| Closure convertie à chaque appel (`qsort`) | 500 000 appels | +99 Mo |
| `g_timeout_add` par événement | 100 000 enregistrements | +44 Mo |
| `g_idle_add` par événement | 100 000 enregistrements | +43 Mo |

Le coût est d'environ **200–650 octets par conversion closure → pointeur de
fonction** selon le chemin, et il n'est pas récupéré. Un handler connecté une
fois est donc utilisable indéfiniment ; en revanche, enregistrer un timer à
chaque tick tuerait l'application en quelques heures.

**Règle :** un jeu fixe de sources GLib enregistrées au démarrage, qui dépilent
des files PHP. Détail dans `docs/decisions.md` (règle R1).

## 4. Architecture cible

```
┌──────────────────────────────────────────────────────────────┐
│  Application PHP (code utilisateur)                          │
│  Window, Box, Button, ListView, DrawingArea…                 │
└───────────────────────────┬──────────────────────────────────┘
                            │ API PHP typée, orientée objet
┌───────────────────────────▼──────────────────────────────────┐
│  cyonima/gtk  — bindings générés                             │
│  • générateur .typelib → classes PHP (build time)            │
│  • wrappers de cycle de vie (ref_sink / unref / parenté)     │
│  • helpers de signaux typés + garde des closures             │
│  • couche de validation anti-segfault                        │
└───────────────────────────┬──────────────────────────────────┘
                            │ FFI::cdef + libgtk-4.so.1 …
┌───────────────────────────▼──────────────────────────────────┐
│  GTK4 · GLib · GObject · Gdk · Pango · Cairo                 │
└──────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────┐
│  cyonima/runtime                                             │
│  • bootstrap app, boucle d'événements, arrêt propre          │
│  • Fibers + g_idle_add : tâches longues sans figer l'UI      │
│  • gestion d'erreurs et logs                                 │
└──────────────────────────────────────────────────────────────┘
```

### Pourquoi les Fibers sont obligatoires

PHP est mono-thread et la boucle GTK est bloquante en C. Une opération de 2 s
(requête SQL, analyse PHPStan, compilation) gèle la fenêtre. Deux parades, à
combiner :

- `Fiber` + `g_idle_add()` : découper le travail et rendre la main à GTK entre
  les tranches ;
- sous-processus (`proc_open`) + surveillance par `g_timeout_add()` pour les
  tâches réellement lourdes (compilation, LSP, PHPStan).

## 5. Brique inattendue : l'IDE peut être natif aussi

Les bibliothèques nécessaires à un IDE sont **déjà présentes** sur une Ubuntu
desktop standard et exposent du C, donc du FFI :

| Besoin IDE | Bibliothèque | État |
|---|---|---|
| Éditeur de code, coloration PHP | `libgtksourceview-5` | installé |
| Terminal intégré | `libvte-2.91-gtk4` | installé |
| Look moderne GNOME | `libadwaita-1` | installé |
| Arborescence fichiers, onglets, palettes | GTK4 natif | installé |
| Complétion / diagnostics | LSP externe (`phpactor`, `intelephense`) | à intégrer |
| Débogage | Xdebug (DBGp sur socket) | à intégrer |

Autrement dit : l'IDE et les applications qu'il produit peuvent partager le
même socle. Un IDE PHP écrit en PHP avec des widgets natifs, ce qui n'existe
plus depuis PHP-GTK.

## 6. Risques et parades

| Risque | Gravité | Parade |
|---|---|---|
| Surface de bindings énorme (Gtk 889, Adw 178, GtkSource 130, Vte 41 types) | Élevée | Générateur `.typelib` + sous-ensemble curaté (~120–150 types pour un MVP) |
| Segfaults sans pile PHP | Élevée | Couche de validation, `g_object_ref_sink`, tests de non-régression mémoire |
| Fuites mémoire (pointeurs non libérés) | Moyenne | Wrapper `unref` dans les destructeurs, `valgrind` en CI |
| Fuite FFI par enregistrement de closure (~450–650 o) | Moyenne | Règle R1 : sources GLib enregistrées une fois, dépilage de files PHP |
| UI figée par une tâche PHP longue | Moyenne | Fibers + `g_idle_add`, ou sous-processus |
| Exceptions dans les handlers | Moyenne | Wrapper qui capture `Throwable` et le remonte hors du callback |
| Disponibilité de GTK4 sur la cible | Faible (Linux) | GTK4 est présent sur toute distro desktop récente ; sinon bundling |
| Packaging d'un PHP statique avec FFI | Moyenne | À valider (`static-php-cli` + `libffi`) — étape dédiée |
| Pas de positionnement de fenêtre en GTK4 | Faible | Contrainte assumée (Wayland) ; utiliser des `GtkPopover` |
| `ffi.enable=preload` par défaut | Faible | Fournir un `php.ini` embarqué avec `ffi.enable=1` |

### Comparaison avec les alternatives

| Critère | GTK4 + FFI | Tauri + PHP sidecar | Electron + FrankenPHP |
|---|---|---|---|
| Widgets natifs | **Oui** | Non (WebView) | Non (Chromium) |
| Démarrage | ~20 ms | ~150–400 ms | ~400–1000 ms |
| RAM de base | Très faible | Faible/Moyenne | Élevée |
| Taille livrée | 0 (GTK système) à ~50–90 Mo (bundlé) | ~15–30 Mo + PHP | ~100–150 Mo |
| Effort initial | **Élevé** | Faible | Faible |
| Accès API desktop (tray, notifications, drag&drop, imprimante) | Direct | Via plugins | Via plugins |
| Maturité écosystème | À construire | Bonne | Excellente |

## 7. Plan proposé

| Étape | Contenu | Durée estimée |
|---|---|---|
| **M0 — Spike** ✅ | Fenêtre, signaux, entry, liste, CSS | fait |
| **M1 — Générateur de bindings** | `.typelib` → PHP. 🟡 Tranche verticale livrée sur Gtk (7 classes, 54 méthodes, tests + différentiel PyGObject) ; extension Adw/GtkSource/Vte à suivre | 6–10 semaines |
| **M2 — Runtime + façade OO** | ✅ Livré : boucle à source unique, timers PHP, fibres, sous-processus, erreurs centralisées, `Application` | 2–4 semaines |
| **M3 — App de démonstration** | ✅ Adw livré (HeaderBar, Preferences, SwitchRow, Toast) ; éditeur GtkSourceView et terminal VTE à suivre | 2–3 semaines |
| **M4 — Éditeur + terminal** | ✅ GtkSourceView 5 (coloration PHP) et VTE (PTY réel) livrés | — |
| **M5 — IDE MVP** | 🟡 navigateur, édition, terminal, LSP (diagnostics + complétion), débogueur Xdebug ; restent variables, recherche | 3–5 mois |
| **M5 — Packaging** | PHP statique + FFI, Flatpak (IDE), AppImage (apps), `.desktop` | 3–4 semaines |

Total réaliste pour un MVP utilisable en solo : **9 à 15 mois**. En équipe de
2–3 personnes sur les mêmes étapes : **4 à 6 mois**. Le chemin critique est M1.

## 8. Décisions actées

Les cinq décisions structurantes ont été tranchées le 19/11/2026 ; le détail,
les conséquences et les règles techniques sont dans `docs/decisions.md`.

| # | Décision |
|---|---|
| ADR-001 | Stack GNOME complet dès M1 : GTK4 + libadwaita + GtkSourceView5 + VTE |
| ADR-002 | Deux couches : `Cyonima\Gtk\Raw` généré + façade OO manuelle avec `->raw()` |
| ADR-003 | UI impérative retained-mode + helpers, sans moteur de re-render |
| ADR-004 | PHP 8.3 minimum, CI 8.3/8.4/8.5 |
| ADR-005 | Flatpak (`org.gnome.Platform`) pour l'IDE, AppImage avec GTK bundlé pour les apps produites |

Deux règles techniques en découlent (détail dans `docs/decisions.md`) :

- **R1** — aucune closure enregistrée par événement : sources GLib uniques au
  démarrage, dépilage de files PHP (fuite mesurée de ~450–650 o par
  enregistrement).
- **R2** — aucune exception ne traverse la frontière C : capture et remontée
  après retour dans PHP.

## 9. Conclusion

La voie « vraie UI native » est techniquement la plus élégante et la plus
performante des trois, et elle est désormais **prouvée sur cette machine**.
Son coût est concentré dans l'outillage (génération de bindings, gestion
mémoire), pas dans le produit final. C'est un pari de fondation : plus long à
démarrer, beaucoup plus solide ensuite, et il rend possible un IDE et un
runtime d'applications qui partagent exactement le même socle natif.
