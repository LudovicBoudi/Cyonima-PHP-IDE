# Packaging

Trois formats, trois niveaux d'autonomie.

| Format | Autonomie | Outils requis | État |
|---|---|---|---|
| **Bundle portable** (`tar.gz`) | PHP + GTK4 système | `tar`, PHP | ✅ testé ici |
| **AppImage** | **PHP + GTK embarqués** (pilotes GPU système) | `appimagetool` (auto-téléchargé) | ✅ construite et exécutée (56 Mo) |
| **Flatpak** | complète (runtime GNOME + PHP compilé) | `flatpak-builder`, `org.gnome.Sdk//49` | ✅ construit et exécuté |

## Bundle portable

```bash
packaging/build-portable.sh          # par défaut VERSION=0.1.0
./dist/cyonima-0.1.0/bin/cyonima               # fenêtre de démarrage
./dist/cyonima-0.1.0/bin/cyonima --project=/chemin/du/projet
./dist/cyonima-0.1.0/bin/cyonima --auto        # scénario de test
```

Le bundle contient `bin/`, `src/`, `examples/`, `spec/`, l'autoloader, le
`.desktop` et l'icône. Il n'embarque ni PHP ni GTK : il faut PHP 8.3+ avec
`ext-ffi` et les bibliothèques GTK4/Adw/GtkSourceView/VTE.

Vérification de bout en bout :

```bash
packaging/test-portable.sh
# Portable OK
# Fichier : /tmp/cyonima_ide_demo.php | ...
```

## Build d'une application projet

### Projet compilé en natif

Un projet compilé n'embarque pas PHP. Les scripts natifs regroupent le binaire,
ses bibliothèques non système et GTK4 si nécessaire :

```bash
cyonima-compile --project=/chemin/du/projet \
  --php=src/main.php --package=appimage

cyonima-compile --project=/chemin/du/projet \
  --php=src/main.php --package=flatpak
```

Sorties : `dist/<slug>-<version>-x86_64.AppImage` et `.flatpak`. Le Flatpak
utilise `org.gnome.Platform//49` et embarque les modules absents du runtime
(par exemple `libssh2`). Les scripts directs sont
`packaging/appimage/build-native-project.sh` et
`packaging/flatpak/build-native-project-flatpak.sh`.

### Projet interprété

Depuis l'environnement de développement, un projet Cyonima interprété peut être
transformé en AppImage autonome. PHP, GTK, le runtime Cyonima et le projet sont
embarqués.

```bash
packaging/appimage/build-php.sh            # une fois
packaging/appimage/build-project.sh /chemin/du/projet
# ou
packaging/appimage/build-project.sh /chemin/du/projet dist/MonApp.AppImage
```

Le script lit `.cyonima/project.json` pour le nom, l'identifiant, l'entrée et la
version du projet, puis produit `dist/<slug>-<version>-x86_64.AppImage`.

Prérequis : `appimagetool` (téléchargé automatiquement si absent) et le PHP
embarqué construit par `build-php.sh`.

### Depuis l'IDE installé

L'AppImage embarque `appimagetool`, `mksquashfs` et `unsquashfs`, ainsi que
`build-project.sh` : elle produit directement une AppImage pour le projet.

Sortie :

```text
<projet>/dist/<slug>-<version>-x86_64.AppImage
```

### Cible Flatpak

Le script `build-project-flatpak.sh` génère un manifeste et un bundle Flatpak
autonome :

```bash
packaging/flatpak/build-project-flatpak.sh /chemin/du/projet
# → /chemin/du/projet/dist/<slug>-<version>-x86_64.flatpak
```

Il requiert `flatpak-builder` et le SDK `org.gnome.Sdk//49`. Le bundle Flatpak
est installable avec :

```bash
flatpak install --user ./<projet>-<version>-x86_64.flatpak
```

La cible Flatpak n'est pas disponible depuis le bac à sable Flatpak de l'IDE ;
elle s'utilise depuis l'IDE source ou l'AppImage avec `flatpak-builder` sur
l'hôte. Le bundle `.tar.gz` reste un secours interne.

Depuis l'IDE, la même opération est disponible via
**Fichier → Exporter l'application autonome**.

## AppImage

```bash
packaging/appimage/build-php.sh        # une fois : PHP 8.3.14 + FFI/SimpleXML/DOM
packaging/appimage/build-appimage.sh   # dist/Cyonima-0.1.0-x86_64.AppImage
packaging/test-appimage.sh
```

L'AppImage **embarque PHP** (8,1 Mo strippé) et ses bibliothèques
(`libxml2`, `libffi`, ICU, lzma, zlib). Plus besoin de PHP sur la machine cible.

Vérifications effectuées :

```bash
env -i HOME=$HOME DISPLAY=:0 APPIMAGE_EXTRACT_AND_RUN=1   ./dist/Cyonima-0.1.0-x86_64.AppImage --version
# Cyonima IDE 0.1.0

./dist/Cyonima-0.1.0-x86_64.AppImage --auto
# Fichier : /tmp/cyonima_ide_demo.php | lignes : 10 | caractères : 147 | ... | terminal : 529464
```

Les deux modes fonctionnent : montage FUSE direct et
`APPIMAGE_EXTRACT_AND_RUN=1` (utile sans FUSE ou sur `/tmp` `noexec`).

L'AppImage embarque **PHP, GTK4, libadwaita, GtkSourceView 5, VTE** et les
schémas/loaders nécessaires (56 Mo, outils d’export inclus). Seuls glibc et les pilotes GPU restent ceux
de l'hôte, comme pour toute AppImage graphique.

Limite : `mbstring` n'est pas compilé (oniguruma absent du système de build) ;
le code utilise un repli `strlen` pour les préfixes ASCII.

## Flatpak

```bash
flatpak install --user flathub org.gnome.Sdk//49 org.gnome.Platform//49
flatpak-builder --user --install --force-clean build-dir \
  packaging/flatpak/com.cyonima.Ide.json
flatpak run com.cyonima.Ide
```

Prérequis exacts (vérifiés) :

```bash
flatpak remote-add --user --if-not-exists flathub https://flathub.org/repo/flathub.flatpakrepo
flatpak install --user -y flathub org.gnome.Sdk//49 org.gnome.Platform//49
```

Build et exécution :

```bash
flatpak-builder --user --install --force-clean --disable-rofiles-fuse \
  build-dir packaging/flatpak/com.cyonima.Ide.json
flatpak run com.cyonima.Ide --auto
# Fichier : /tmp/cyonima_ide_demo.php | lignes : 10 | caractères : 147 | ... | terminal : 8
```

Les projets créés depuis la fenêtre de démarrage lancent l'IDE avec
`--project`. Leur `autoload.php` cherche le runtime via `CYONIMA_HOME`, défini
par le lanceur ; le chemin d'installation sert de repli.

Le manifeste :

1. construit **PHP 8.3.14** depuis l'archive officielle (SHA256 vérifié sur
   php.net) avec `--with-ffi`, `simplexml`, `dom`, `libxml`, `mbstring`,
   `opcache` ;
2. construit **VTE 0.76.0** : le runtime GNOME fournit GTK4, libadwaita et
   GtkSourceView 5, mais **pas VTE** ;
3. copie l'application dans `/app/share/cyonima` et installe `/app/bin/cyonima` ;
4. s'appuie sur `org.gnome.Platform//49`.

Détails qui ont demandé une correction :

- VTE s'installait dans `/app/lib64`, absent du chemin de recherche du bac à
  sable (`/etc/ld.so.conf` ne référence que `/app/lib`). Le module est construit
  avec `-Dlibdir=lib`, et le lanceur exporte `LD_LIBRARY_PATH=/app/lib:/app/lib64`
  par sécurité.
- PHP est compilé depuis l'archive (et non le dépôt git) car `re2c` n'est pas
  présent dans le SDK ; l'archive contient les parseurs générés.
- `libsystemd`, `oniguruma`, `pcre2`, `liblz4`, `libffi` et `libxml2` sont bien
  fournis par le SDK.

Vérification rapide une fois installé :

```bash
packaging/test-flatpak.sh      # Flatpak OK + ligne de sortie
```

Permissions : `--filesystem=host` et `--share=network` (outil de développement),
`--socket=session-bus` pour les intégrations de bureau.

Limites connues :

- le premier build est long (téléchargement du SDK ~830 Mo, compilation PHP et
  VTE) ; les suivants réutilisent le cache ;
- Xdebug n'est pas inclus dans l'image (module séparé nécessaire pour déboguer
  depuis le Flatpak) ;
- `--filesystem=host` reste nécessaire tant que l'IDE n'utilise pas le portail
  de fichiers.

## Validation

```bash
php packaging/check.php
# Packaging valide : manifeste Flatpak, desktop, icône et lanceurs.
```

Le contrôleur vérifie : JSON du manifeste, `app-id`, présence de `command` et
son installation dans `/app/bin`, sources locales existantes, champs obligatoires
du `.desktop`, icône et bits exécutables.

## Versionnement

`VERSION=1.2.3 packaging/build-portable.sh` contrôle le nom des artefacts.
Le lanceur expose `cyonima --version`.


## Extensions PHP natives

Le PHP embarqué de l'AppImage et du Flatpak inclut `ssh2` (libssh2), `openssl`,
`sockets`, `pcntl`, `posix`, `curl`, `intl`, `pdo_sqlite`/`sqlite3`, `zip`,
`gd` et `ctype`. Le module `ssh2` est compilé dans le Flatpak via
`libssh2` + PECL `ssh2`, et ajouté au PHP AppImage.

Les applications exportées avec `build-project.sh` ou
`build-project-flatpak.sh` embarquent ces extensions : le manifeste Flatpak de
projet ajoute les modules `libssh2` et PECL `ssh2`.
