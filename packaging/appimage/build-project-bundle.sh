#!/usr/bin/env bash
#
# Construit un bundle portable autonome pour un projet Cyonima.
#
# Le bundle embarque PHP, GTK, le runtime Cyonima et le projet, sans dépendre
# d'appimagetool. Il est utilisable depuis l'IDE packagé (AppImage ou Flatpak)
# en réutilisant son propre runtime.
#
# Usage :
#   CYONIMA_RUNTIME_ROOT=/chemin/vers/runtime build-project-bundle.sh \
#       /chemin/du/projet [sortie.tar.gz]

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PROJECT="${1:?Usage : build-project-bundle.sh /chemin/du/projet [sortie.tar.gz]}"
PROJECT="$(cd "$PROJECT" && pwd)"
RUNTIME_ROOT="${CYONIMA_RUNTIME_ROOT:?CYONIMA_RUNTIME_ROOT requis}"
PHP_CLI="${PHP_CLI:-php}"

if [ -x "$RUNTIME_ROOT/usr/bin/php" ]; then
    PHP_BIN="$RUNTIME_ROOT/usr/bin/php"
    LIB_SRC="$RUNTIME_ROOT/usr/lib"
    SHARE_SRC="$RUNTIME_ROOT/usr/share"
    RUNTIME_SRC="$RUNTIME_ROOT/usr/share/cyonima"
elif [ -x "$RUNTIME_ROOT/bin/php" ]; then
    PHP_BIN="$RUNTIME_ROOT/bin/php"
    LIB_SRC="$RUNTIME_ROOT/lib"
    SHARE_SRC="$RUNTIME_ROOT/share"
    RUNTIME_SRC="$RUNTIME_ROOT/share/cyonima"
else
    echo "Runtime autonome introuvable dans $RUNTIME_ROOT" >&2
    exit 1
fi

if [ ! -f "$RUNTIME_SRC/autoload.php" ] || [ ! -d "$RUNTIME_SRC/src" ]; then
    echo "Runtime Cyonima introuvable dans $RUNTIME_SRC" >&2
    exit 1
fi

if ! command -v "$PHP_CLI" >/dev/null 2>&1; then
    echo "PHP hôte introuvable pour lire le manifeste." >&2
    exit 1
fi

if [ ! -f "$PROJECT/.cyonima/project.json" ]; then
    echo "Projet invalide : .cyonima/project.json manquant dans $PROJECT" >&2
    exit 1
fi

mapfile -t META < <("$PHP_CLI" "$ROOT/packaging/appimage/project-meta.php" "$PROJECT")
NAME="${META[0]:-Application}"
SLUG="${META[1]:-application}"
APP_ID="${META[2]:-com.example.application}"
ENTRY="${META[3]:-src/main.php}"
VERSION="${META[4]:-0.1.0}"

case "$ENTRY" in
    /*|../*|*/../*)
        echo "Entrée de projet invalide : $ENTRY" >&2
        exit 1
        ;;
esac

if [ ! -f "$PROJECT/$ENTRY" ]; then
    echo "Entrée de projet introuvable : $PROJECT/$ENTRY" >&2
    exit 1
fi

OUTPUT="${2:-$ROOT/dist/${SLUG}-${VERSION}-linux-x86_64.tar.gz}"
BUNDLE_NAME="${SLUG}-${VERSION}"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/cyonima-bundle-XXXXXX")"
BUNDLE="$WORK/$BUNDLE_NAME"
ICON="$PROJECT/assets/icon.svg"
[ -f "$ICON" ] || ICON="$ROOT/packaging/icon/com.cyonima.Ide.svg"

trap 'rm -rf "$WORK"' EXIT
mkdir -p "$BUNDLE/bin" "$BUNDLE/lib" "$BUNDLE/share/app" "$BUNDLE/share/cyonima"

cp -L "$PHP_BIN" "$BUNDLE/bin/php"
chmod 0755 "$BUNDLE/bin/php"

# Bibliothèques PHP et GTK fournies par le runtime courant.
# Dans un Flatpak, GTK est fourni par le runtime /usr ; on le collecte avec
# bundle-gtk.sh quand le runtime /app ne contient pas libgtk.
mkdir -p "$BUNDLE/lib"
if [ ! -e "$LIB_SRC/libgtk-4.so.1" ] && [ -x "$ROOT/packaging/appimage/bundle-gtk.sh" ]; then
    mkdir -p "$WORK/gtk"
    APPDIR="$WORK/gtk" bash "$ROOT/packaging/appimage/bundle-gtk.sh"
    cp -a "$WORK/gtk/usr/lib/." "$BUNDLE/lib/"
fi
cp -a "$LIB_SRC/." "$BUNDLE/lib/"
if [ -d "$LIB_SRC/php" ]; then
    cp -a "$LIB_SRC/php" "$BUNDLE/lib/"
fi

# Ressources GTK utiles (schémas, icônes, loaders GdkPixbuf).
for resource in glib-2.0 icons gdk-pixbuf-2.0 mime fontconfig; do
    if [ -e "$SHARE_SRC/$resource" ]; then
        mkdir -p "$BUNDLE/share/$(dirname "$resource")"
        cp -a "$SHARE_SRC/$resource" "$BUNDLE/share/"
    fi
    if [ -e "$WORK/gtk/usr/share/$resource" ]; then
        mkdir -p "$BUNDLE/share/$(dirname "$resource")"
        cp -a "$WORK/gtk/usr/share/$resource" "$BUNDLE/share/"
    fi
done

# Runtime et projet.
cp -r "$RUNTIME_SRC/autoload.php" "$RUNTIME_SRC/src" "$BUNDLE/share/cyonima/"
cp -a "$PROJECT/." "$BUNDLE/share/app/"

cat > "$BUNDLE/AppRun" <<EOF
#!/bin/sh
# Bundle autonome généré par Cyonima IDE.

set -eu

HERE=\$(dirname "\$(readlink -f "\$0")")

export LD_LIBRARY_PATH="\$HERE/lib\${LD_LIBRARY_PATH:+:\$LD_LIBRARY_PATH}"
export XDG_DATA_DIRS="\$HERE/share:\${XDG_DATA_DIRS:-/usr/local/share:/usr/share}"
export GSETTINGS_SCHEMA_DIR="\$HERE/share/glib-2.0/schemas"
export CYONIMA_HOME="\$HERE/share/cyonima"

RUNTIME_DIR="\${XDG_RUNTIME_DIR:-/tmp}"
CACHE="\$RUNTIME_DIR/cyonima-gdk-pixbuf-loaders-\$\$.cache"
QUERY="\$HERE/lib/gdk-pixbuf-2.0/gdk-pixbuf-query-loaders"
LOADERS="\$HERE/lib/gdk-pixbuf-2.0/2.10.0/loaders"

if [ -x "\$QUERY" ] && [ -d "\$LOADERS" ]; then
    "\$QUERY" "\$LOADERS"/*.so > "\$CACHE" 2>/dev/null || true
    if [ -s "\$CACHE" ]; then
        export GDK_PIXBUF_MODULE_FILE="\$CACHE"
    fi
fi

PHP_EXT_DIR="\$HERE/lib/php/extensions/no-debug-non-zts-20230831"
PHP_EXTRA=""
if [ -f "\$PHP_EXT_DIR/ssh2.so" ]; then
    PHP_EXTRA="-d extension_dir=\$PHP_EXT_DIR -d extension=ssh2"
fi

cd "\$HERE/share/app"
exec "\$HERE/bin/php" -n -d ffi.enable=1 \$PHP_EXTRA "\$HERE/share/app/$ENTRY" "\$@"
EOF
chmod 0755 "$BUNDLE/AppRun"

cat > "$BUNDLE/$APP_ID.desktop" <<EOF
[Desktop Entry]
Type=Application
Name=$NAME
Comment=Application créée avec Cyonima IDE
Exec=$SLUG
Icon=$APP_ID
Terminal=false
Categories=Utility;
StartupNotify=true
StartupWMClass=$APP_ID
EOF

install -m 0644 "$ICON" "$BUNDLE/$APP_ID.svg"

mkdir -p "$(dirname "$OUTPUT")"
tar -C "$WORK" -czf "$OUTPUT" "$BUNDLE_NAME"

echo "Bundle : $OUTPUT ($(du -h "$OUTPUT" | cut -f1))"
