#!/usr/bin/env bash
#
# Construit une AppImage pour un projet compilé en natif.
#
# Usage :
#   build-native-project.sh PROJECT BINARY NAME SLUG APP_ID VERSION [OUTPUT]
#
# L'AppImage embarque le binaire, ses bibliothèques non système, et GTK4 si le
# binaire en dépend. PHP n'est pas embarqué : le programme est déjà compilé.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PROJECT="${1:?Usage : build-native-project.sh PROJECT BINARY NAME SLUG APP_ID VERSION [OUTPUT]}"
BINARY="${2:?binaire requis}"
NAME="${3:-Application}"
SLUG="${4:-application}"
APP_ID="${5:-com.example.application}"
VERSION="${6:-0.1.0}"
OUTPUT="${7:-}"

PROJECT="$(cd "$PROJECT" && pwd)"
BINARY="$(cd "$(dirname "$BINARY")" && pwd)/$(basename "$BINARY")"

if [ ! -x "$BINARY" ]; then
    echo "Binaire introuvable ou non exécutable : $BINARY" >&2
    exit 1
fi

if [ -z "$OUTPUT" ]; then
    OUTPUT="$PROJECT/dist/${SLUG}-${VERSION}-x86_64.AppImage"
fi

mkdir -p "$(dirname "$OUTPUT")"
OUTPUT="$(cd "$(dirname "$OUTPUT")" && pwd)/$(basename "$OUTPUT")"

WORK="$(mktemp -d "${TMPDIR:-/tmp}/cyonima-native-appimage-XXXXXX")"
APPDIR="$WORK/${SLUG}.AppDir"
trap 'rm -rf "$WORK"' EXIT

mkdir -p "$APPDIR/usr/bin" "$APPDIR/usr/lib"
install -m 0755 "$BINARY" "$APPDIR/usr/bin/$SLUG"

declare -A SEEN=()

is_excluded() {
    local base
    base="$(basename "$1")"

    case "$base" in
        libc.so.*|libm.so.*|libpthread.so.*|libdl.so.*|librt.so.*|libresolv.so.*|\
        libnss_*.so*|ld-linux*.so.*|libutil.so.*|libanl.so.*|libmvec.so.*)
            return 0
            ;;
        libGL.so.*|libGLX.so.*|libGLdispatch.so.*|libOpenGL.so.*|libEGL.so.*|\
        libgbm.so.*|libdrm.so.*|libvulkan.so.*|libLLVM*.so*|libglapi.so.*|\
        libxcb-dri*.so.*|libvdpau*.so.*|libva*.so.*)
            return 0
            ;;
    esac

    return 1
}

collect() {
    local queue=("$@")

    while [ ${#queue[@]} -gt 0 ]; do
        local lib="${queue[0]}"
        queue=("${queue[@]:1}")

        [ -n "${SEEN[$lib]:-}" ] && continue
        SEEN[$lib]=1

        is_excluded "$lib" && continue
        [ -f "$lib" ] || continue

        local target="$APPDIR/usr/lib/$(basename "$lib")"

        if [ ! -e "$target" ]; then
            cp -L "$lib" "$target"
        fi

        while IFS= read -r dep; do
            [ -n "$dep" ] && queue+=("$dep")
        done < <(ldd "$lib" 2>/dev/null | awk '{print $3}' | grep '^/' || true)
    done
}

while IFS= read -r library; do
    [ -n "$library" ] && collect "$library"
done < <(ldd "$BINARY" 2>/dev/null | awk '{print $3}' | grep '^/' || true)

NEEDS_GTK=0
if ldd "$BINARY" 2>/dev/null | grep -q 'libgtk-4'; then
    NEEDS_GTK=1
    APPDIR="$APPDIR" bash "$ROOT/packaging/appimage/bundle-gtk.sh"
fi

ICON="$PROJECT/assets/icon.svg"
if [ ! -f "$ICON" ]; then
    ICON="$ROOT/packaging/icon/com.cyonima.Ide.svg"
fi

mkdir -p "$APPDIR/usr/share/applications" \
    "$APPDIR/usr/share/icons/hicolor/scalable/apps"

cat > "$APPDIR/AppRun" <<EOF
#!/bin/sh
# AppImage native générée par Cyonima IDE.

set -eu

HERE=\$(dirname "\$(readlink -f "\$0")")

export LD_LIBRARY_PATH="\$HERE/usr/lib\${LD_LIBRARY_PATH:+:\$LD_LIBRARY_PATH}"
export XDG_DATA_DIRS="\$HERE/usr/share:\${XDG_DATA_DIRS:-/usr/local/share:/usr/share}"
export GSETTINGS_SCHEMA_DIR="\$HERE/usr/share/glib-2.0/schemas"

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

exec "\$HERE/usr/bin/$SLUG" "\$@"
EOF
chmod 0755 "$APPDIR/AppRun"

cat > "$APPDIR/$APP_ID.desktop" <<EOF
[Desktop Entry]
Type=Application
Name=$NAME
Comment=Application native créée avec Cyonima IDE
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

TOOL="${APPIMAGETOOL:-}"

if [ -z "$TOOL" ] && [ -x "$ROOT/tools/appimagetool" ]; then
    TOOL="$ROOT/tools/appimagetool"
fi
if [ -z "$TOOL" ] && [ -x "$ROOT/.tools/appimagetool" ]; then
    TOOL="$ROOT/.tools/appimagetool"
fi
if [ -z "$TOOL" ] && command -v appimagetool >/dev/null 2>&1; then
    TOOL="$(command -v appimagetool)"
fi

if [ -z "$TOOL" ]; then
    mkdir -p "$ROOT/.tools"
    TOOL="$ROOT/.tools/appimagetool"
    if [ ! -x "$TOOL" ]; then
        echo "Téléchargement d'appimagetool…"
        curl -fsSL -o "$TOOL" \
            "https://github.com/AppImage/AppImageKit/releases/download/continuous/appimagetool-x86_64.AppImage"
        chmod +x "$TOOL"
    fi
fi

if [ -d "$ROOT/tools" ]; then
    export PATH="$ROOT/tools:$PATH"
    if [ -d "$ROOT/tools/lib" ]; then
        export LD_LIBRARY_PATH="$ROOT/tools/lib${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
    fi
fi

rm -f "$OUTPUT"
ARCH=x86_64 APPIMAGE_EXTRACT_AND_RUN=1 "$TOOL" "$APPDIR" "$OUTPUT" >/dev/null

echo "AppImage native : $OUTPUT ($(du -h "$OUTPUT" | cut -f1))"
