#!/usr/bin/env bash
#
# Construit l'AppImage avec PHP et la pile GTK embarqués.
#
# Prérequis :
#   packaging/appimage/build-php.sh   (une fois)
#   appimagetool                      (téléchargé automatiquement si absent)
#
# GTK4, libadwaita, GtkSourceView et VTE sont copiés avec leurs dépendances,
# leurs schémas GSettings, leurs loaders GdkPixbuf et les thèmes d'icônes.
# Les pilotes graphiques (GL/EGL/Vulkan/DRM) et glibc restent ceux de l'hôte.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
VERSION="${VERSION:-0.1.0}"
PHP_DIR="${CYONIMA_PHP_DIR:-$ROOT/build/appimage-php/php}"
APPDIR="$ROOT/dist/Cyonima.AppDir"
OUTPUT="$ROOT/dist/Cyonima-$VERSION-x86_64.AppImage"
BUNDLE_GTK="${BUNDLE_GTK:-1}"

if [ ! -x "$PHP_DIR/usr/bin/php" ]; then
    echo "PHP embarqué absent : lancez d'abord packaging/appimage/build-php.sh" >&2
    exit 1
fi

rm -rf "$APPDIR" "$OUTPUT"
mkdir -p "$APPDIR/usr/bin" "$APPDIR/usr/lib" "$APPDIR/usr/share/cyonima"

# --- PHP et ses dépendances ------------------------------------------------
install -m 0755 "$PHP_DIR/usr/bin/php" "$APPDIR/usr/bin/php"

while IFS= read -r library; do
    case "$(basename "$library")" in
        libc.so.*|libm.so.*|libpthread.so.*|libdl.so.*|librt.so.*|ld-linux*|libresolv*|libnss*)
            continue
            ;;
    esac
    target="$APPDIR/usr/lib/$(basename "$library")"
    [ -e "$target" ] || cp -L "$library" "$target"
done < <(ldd "$APPDIR/usr/bin/php" | awk '{print $3}' | grep '^/' || true)

# --- Pile GTK --------------------------------------------------------------
if [ "$BUNDLE_GTK" != "0" ]; then
    APPDIR="$APPDIR" bash "$ROOT/packaging/appimage/bundle-gtk.sh"
fi

# --- Application -----------------------------------------------------------
cp -r \
    "$ROOT/autoload.php" \
    "$ROOT/src" \
    "$ROOT/examples" \
    "$ROOT/spec" \
    "$APPDIR/usr/share/cyonima/"

mkdir -p "$APPDIR/usr/share/cyonima/bin"
install -m 0644 "$ROOT/bin/cyonima.php" "$APPDIR/usr/share/cyonima/bin/cyonima.php"

# --- Intégration bureau ----------------------------------------------------
mkdir -p \
    "$APPDIR/usr/share/applications" \
    "$APPDIR/usr/share/icons/hicolor/scalable/apps"

install -m 0755 "$ROOT/packaging/appimage/AppRun" "$APPDIR/AppRun"
install -m 0644 "$ROOT/packaging/com.cyonima.Ide.desktop" "$APPDIR/com.cyonima.Ide.desktop"
install -m 0644 "$ROOT/packaging/icon/com.cyonima.Ide.svg" "$APPDIR/com.cyonima.Ide.svg"
install -m 0644 "$ROOT/packaging/com.cyonima.Ide.desktop" \
    "$APPDIR/usr/share/applications/com.cyonima.Ide.desktop"
install -m 0644 "$ROOT/packaging/icon/com.cyonima.Ide.svg" \
    "$APPDIR/usr/share/icons/hicolor/scalable/apps/com.cyonima.Ide.svg"

# --- appimagetool ----------------------------------------------------------
TOOL="${APPIMAGETOOL:-}"

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

ARCH=x86_64 APPIMAGE_EXTRACT_AND_RUN=1 "$TOOL" "$APPDIR" "$OUTPUT"

echo "AppImage : $OUTPUT ($(du -h "$OUTPUT" | cut -f1))"
