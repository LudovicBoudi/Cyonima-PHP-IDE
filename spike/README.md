# Cyonima — Spike GTK4 natif depuis PHP

Deux POC autonomes, sans dépendance Composer, sans WebView, sans serveur HTTP.

## Prérequis

```bash
php -v                      # >= 8.3 validé (8.3.6)
php -m | grep FFI           # extension FFI présente
pkg-config --modversion gtk4 || dpkg -l libgtk-4-1   # GTK4 runtime 4.14.5 validé
echo $DISPLAY               # un serveur X/Wayland actif
```

Aucun paquet `-dev` n'est nécessaire : les POC déclarent les symboles C directement
(`FFI::cdef`) et chargent `libgtk-4.so.1` / `libgobject-2.0.so.0`.

L'extension FFI est désactivée par défaut (`ffi.enable=preload`). On l'active
explicitement à l'exécution :

## Lancer

```bash
php -d ffi.enable=true spike/gtk_hello.php       # fenêtre + compteur de clics
php -d ffi.enable=true spike/gtk_list_css.php    # entry, liste, CSS, rafale d'événements
```

Les deux fenêtres se ferment seules après quelques secondes (scénario automatisé).

## Ce que démontre chaque POC

### `gtk_hello.php`
- `gtk_init_check()` puis fenêtre GTK4 réelle, sans aucun code C.
- Une closure PHP branchée sur le signal `clicked` d'un `GtkButton` : GTK appelle
  directement une fonction PHP depuis sa boucle d'événements C.
- Un clic synthétique émis par `g_signal_emit_by_name()` (utile pour les tests).
- Fermeture propre via `close-request` + `g_main_loop_quit()`.

### `gtk_list_css.php`
- Saisie texte bidirectionnelle : `GtkEntry` → `gtk_editable_get_text()` → PHP.
- Création/suppression dynamique de widgets (`GtkListBox`) depuis PHP.
- CSS complet chargé depuis une chaîne PHP (`gtk_css_provider_load_from_string`).
- Signals `clicked` **et** `activate` (touche Entrée).
- Rafale de 50 créations/suppressions dans un seul callback, sans fuite (pic 2 Mo).

## Piège documenté

`gtk_list_box_append(list, child)` emballe l'enfant dans un `GtkListBoxRow`
implicite. Pour retirer une ligne, il faut passer **la row**, pas l'enfant :

```php
$row = $gtk->gtk_list_box_row_new();
$gtk->gtk_list_box_row_set_child($row, $label);
$gtk->gtk_list_box_append($list, $row);
// plus tard :
$gtk->gtk_list_box_remove($list, $row);   // et non $label
```

C'est l'illustration du coût réel de l'approche : le FFI n'offre aucun filet de
sécurité sur les invariants GObject. D'où la recommandation de générer les
bindings depuis les `.typelib` (voir `docs/decisions.md`).

## Mesures de fuite mémoire

```bash
php -d ffi.enable=true spike/leak.php qsort 500000
php -d ffi.enable=true spike/leak.php gtk   300000
php -d ffi.enable=true spike/timers.php timeout 100000
php -d ffi.enable=true spike/timers.php idle    100000
php -d ffi.enable=true spike/timers.php remove  100000
```

Résultats sur PHP 8.3.6 :

| Scénario | Delta RSS |
|---|---|
| 300 000 invocations d'un signal GTK (handler connecté une fois) | +1,75 Mo, plateau |
| 500 000 conversions closure → pointeur (`qsort`) | +99 Mo |
| 100 000 `g_timeout_add` | +44 Mo |
| 100 000 `g_idle_add` | +43 Mo |
| 100 000 `g_idle_add` + `g_source_remove` | +20 Mo |

Conclusion : la fuite est **par enregistrement de closure** (~200–650 o selon
le chemin), pas par invocation. Règle R1 dans `docs/decisions.md`.

## Lecture des métadonnées d'API (`.typelib`)

```bash
php -d ffi.enable=true spike/typelib.php
```

Sortie sur cette machine :

```
Gtk        4.0     889 types  (ex: ACCESSIBLE_ATTRIBUTE_BACKGROUND, …)
Adw        1       178 types  (ex: AboutDialog, AboutDialogClass, …)
GtkSource  5       130 types  (ex: BackgroundPatternType, BracketMatchType, …)
Vte        3.91     41 types  (ex: Align, CharAttributes, CursorBlinkMode)
GObject    2.0     312 types  (ex: Array, BaseFinalizeFunc, BaseInitFunc)
```

Les `.typelib` sont lisibles via `libgirepository-1.0.so.1` **sans paquet
`-dev`**. C'est la source de vérité retenue pour le générateur de bindings :
elle est présente dans le runtime Flatpak GNOME, donc reproductible en CI.
