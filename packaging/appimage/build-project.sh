#!/usr/bin/env bash
#
# Construit une AppImage autonome pour un projet Cyonima.
#
# Usage : build-project.sh /chemin/du/projet [sortie.AppImage]
#
# L'AppImage embarque PHP, la pile GTK, le runtime Cyonima et le projet.
# Prérequis : packaging/appimage/build-php.sh déjà exécuté et appimagetool.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PROJECT="${1:?Usage : build-project.sh /chemin/du/projet [sortie.AppImage]}"
PROJECT="$(cd "$PROJECT" && pwd)"
PHP_DIR="${CYONIMA_PHP_DIR:-$ROOT/build/appimage-php/php}"
PHP_CLI="${PHP_CLI:-php}"
BUNDLE_GTK="${BUNDLE_GTK:-1}"
RUNTIME_ROOT="${CYONIMA_RUNTIME_ROOT:-}"

if [ -n "$RUNTIME_ROOT" ]; then
    if [ -x "$RUNTIME_ROOT/usr/bin/php" ]; then
        PHP_BIN="$RUNTIME_ROOT/usr/bin/php"
        LIB_SRC="$RUNTIME_ROOT/usr/lib"
        SHARE_SRC="$RUNTIME_ROOT/usr/share"
        RUNTIME_SRC="$RUNTIME_ROOT/usr/share/cyonima"
        PHP_EXT_SRC="$RUNTIME_ROOT/usr/lib/php"
    elif [ -x "$RUNTIME_ROOT/bin/php" ]; then
        PHP_BIN="$RUNTIME_ROOT/bin/php"
        LIB_SRC="$RUNTIME_ROOT/lib"
        SHARE_SRC="$RUNTIME_ROOT/share"
        RUNTIME_SRC="$RUNTIME_ROOT/share/cyonima"
        PHP_EXT_SRC="$RUNTIME_ROOT/lib/php"
    else
        echo "Runtime autonome introuvable dans $RUNTIME_ROOT" >&2
        exit 1
    fi
elif [ -x "$PHP_DIR/usr/bin/php" ]; then
    PHP_BIN="$PHP_DIR/usr/bin/php"
    PHP_EXT_SRC="$PHP_DIR/usr/lib/php"
    RUNTIME_SRC="$ROOT"
else
    echo "PHP embarqué absent : lancez d'abord packaging/appimage/build-php.sh" >&2
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

ENTRY_PATH="$PROJECT/$ENTRY"
if [ ! -f "$ENTRY_PATH" ]; then
    echo "Entrée de projet introuvable : $ENTRY_PATH" >&2
    exit 1
fi

if [ -n "$RUNTIME_ROOT" ]; then
    OUTPUT="${2:-$PROJECT/dist/${SLUG}-${VERSION}-x86_64.AppImage}"
    WORK="$(mktemp -d "${TMPDIR:-/tmp}/cyonima-appimage-XXXXXX")"
    APPDIR="$WORK/${SLUG}.AppDir"
    trap 'rm -rf "$WORK"' EXIT
else
    OUTPUT="${2:-$ROOT/dist/${SLUG}-${VERSION}-x86_64.AppImage}"
    APPDIR="$ROOT/dist/${SLUG}.AppDir"
fi
ICON="$PROJECT/assets/icon.svg"
[ -f "$ICON" ] || ICON="$ROOT/packaging/icon/com.cyonima.Ide.svg"

rm -rf "$APPDIR" "$OUTPUT"
mkdir -p "$APPDIR/usr/bin" "$APPDIR/usr/lib" "$APPDIR/usr/share/cyonima" "$APPDIR/usr/share/app"

# --- PHP et ses dépendances ------------------------------------------------
install -m 0755 "$PHP_BIN" "$APPDIR/usr/bin/php"

if [ -n "$RUNTIME_ROOT" ]; then
    mkdir -p "$APPDIR/usr/lib"
    if [ -e "$LIB_SRC/libgtk-4.so.1" ]; then
        cp -a "$LIB_SRC/." "$APPDIR/usr/lib/"
    elif [ "$BUNDLE_GTK" != "0" ] && [ -x "$ROOT/packaging/appimage/bundle-gtk.sh" ]; then
        APPDIR="$APPDIR" bash "$ROOT/packaging/appimage/bundle-gtk.sh"
    fi

    for resource in glib-2.0 icons gdk-pixbuf-2.0 mime fontconfig; do
        if [ -e "$SHARE_SRC/$resource" ]; then
            mkdir -p "$APPDIR/usr/share/$(dirname "$resource")"
            cp -a "$SHARE_SRC/$resource" "$APPDIR/usr/share/"
        fi
    done
else
    while IFS= read -r library; do
        case "$(basename "$library")" in
            libc.so.*|libm.so.*|libpthread.so.*|libdl.so.*|librt.so.*|ld-linux*|libresolv*|libnss*)
                continue
                ;;
        esac
        target="$APPDIR/usr/lib/$(basename "$library")"
        [ -e "$target" ] || cp -L "$library" "$target"
    done < <(ldd "$APPDIR/usr/bin/php" | awk '{print $3}' | grep '^/' || true)

    if [ "$BUNDLE_GTK" != "0" ]; then
        APPDIR="$APPDIR" bash "$ROOT/packaging/appimage/bundle-gtk.sh"
    fi
fi

# --- Extensions PHP --------------------------------------------------------
if [ -n "${PHP_EXT_SRC:-}" ] && [ -d "$PHP_EXT_SRC" ]; then
    mkdir -p "$APPDIR/usr/lib/php"
    cp -a "$PHP_EXT_SRC/." "$APPDIR/usr/lib/php/"
    find "$APPDIR/usr/lib/php" -name '*.so' -print0 | while IFS= read -r -d '' extension; do
        while IFS= read -r library; do
            case "$(basename "$library")" in
                libc.so.*|libm.so.*|libpthread.so.*|libdl.so.*|librt.so.*|ld-linux*|libresolv*|libnss*)
                    continue
                    ;;
            esac
            target="$APPDIR/usr/lib/$(basename "$library")"
            [ -e "$target" ] || cp -L "$library" "$target"
        done < <(ldd "$extension" 2>/dev/null | awk '{print $3}' | grep '^/' || true)
    done
fi

# --- Runtime Cyonima -------------------------------------------------------
cp -r "$RUNTIME_SRC/autoload.php" "$RUNTIME_SRC/src" "$APPDIR/usr/share/cyonima/"

# --- Projet utilisateur ----------------------------------------------------
cp -a "$PROJECT/." "$APPDIR/usr/share/app/"

# --- Lanceur ---------------------------------------------------------------
cat > "$APPDIR/AppRun" <<EOF
#!/bin/sh
# AppImage générée par Cyonima IDE.

set -eu

HERE=\$(dirname "\$(readlink -f "\$0")")

export LD_LIBRARY_PATH="\$HERE/usr/lib\${LD_LIBRARY_PATH:+:\$LD_LIBRARY_PATH}"
export XDG_DATA_DIRS="\$HERE/usr/share:\${XDG_DATA_DIRS:-/usr/local/share:/usr/share}"
export GSETTINGS_SCHEMA_DIR="\$HERE/usr/share/glib-2.0/schemas"
export CYONIMA_HOME="\$HERE/usr/share/cyonima"

RUNTIME_DIR="\${XDG_RUNTIME_DIR:-/tmp}"
CACHE="\$RUNTIME_DIR/cyonima-gdk-pixbuf-loaders-\$\$.cache"
QUERY="\$HERE/usr/lib/gdk-pixbuf-2.0/gdk-pixbuf-query-loaders"
LOADERS="\$HERE/usr/lib/gdk-pixbuf-2.0/2.10.0/loaders"

if [ -x "\$QUERY" ] && [ -d "\$LOADERS" ]; then
    "\$QUERY" "\$LOADERS"/*.so > "\$CACHE" 2>/dev/null || true
    if [ -s "\$CACHE" ]; then
        export GDK_PIXBUF_MODULE_FILE="\$CACHE"
    fi
fi

PHP_EXT_DIR="\$HERE/usr/lib/php/extensions/no-debug-non-zts-20230831"
PHP_EXTRA=""
if [ -f "\$PHP_EXT_DIR/ssh2.so" ]; then
    PHP_EXTRA="-d extension_dir=\$PHP_EXT_DIR -d extension=ssh2"
fi

cd "\$HERE/usr/share/app"
exec "\$HERE/usr/bin/php" -n -d ffi.enable=1 \$PHP_EXTRA "\$HERE/usr/share/app/$ENTRY" "\$@"
EOF
chmod 0755 "$APPDIR/AppRun"

# --- Intégration bureau ----------------------------------------------------
mkdir -p \
    "$APPDIR/usr/share/applications" \
    "$APPDIR/usr/share/icons/hicolor/scalable/apps"

cat > "$APPDIR/$APP_ID.desktop" <<EOF
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

install -m 0644 "$APPDIR/$APP_ID.desktop" "$APPDIR/usr/share/applications/$APP_ID.desktop"
install -m 0644 "$ICON" "$APPDIR/$APP_ID.svg"
install -m 0644 "$ICON" "$APPDIR/usr/share/icons/hicolor/scalable/apps/$APP_ID.svg"

# --- appimagetool ----------------------------------------------------------
TOOL="${APPIMAGETOOL:-}"

if [ -z "$TOOL" ] && [ -x "$ROOT/tools/appimagetool" ]; then
    TOOL="$ROOT/tools/appimagetool"
fi

if [ -z "$TOOL" ]; then
    if command -v appimagetool >/dev/null 2>&1; then
        TOOL="$(command -v appimagetool)"
    else
        mkdir -p "$ROOT/.tools"
        TOOL="$ROOT/.tools/appimagetool"
        if [ ! -x "$TOOL" ]; then
            echo "Téléchargement d'appimagetool…"
            curl -fsSL -o "$TOOL" \
                "https://github.com/AppImage/AppImageKit/releases/download/continuous/appimagetool-x86_64.AppImage"
            chmod +x "$TOOL"
        fi
    fi
fi

if [ -d "$ROOT/tools" ]; then
    export PATH="$ROOT/tools:$PATH"
    if [ -d "$ROOT/tools/lib" ]; then
        export LD_LIBRARY_PATH="$ROOT/tools/lib${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
    fi
fi

mkdir -p "$(dirname "$OUTPUT")"
ARCH=x86_64 APPIMAGE_EXTRACT_AND_RUN=1 "$TOOL" "$APPDIR" "$OUTPUT"

echo "AppImage : $OUTPUT ($(du -h "$OUTPUT" | cut -f1))"
