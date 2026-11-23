#!/usr/bin/env bash
#
# Embarde GTK4, libadwaita, GtkSourceView et VTE dans un AppDir, avec leurs
# dépendances, les schémas GSettings, les loaders GdkPixbuf et les thèmes
# d'icônes.
#
# Exclus volontairement : glibc et les pilotes graphiques (GL/EGL/Vulkan/DRM),
# qui doivent venir du système hôte.

set -euo pipefail

APPDIR="${APPDIR:?APPDIR requis}"
LIBDIR="$APPDIR/usr/lib"
SYSTEM_LIB="/usr/lib/x86_64-linux-gnu"

mkdir -p "$LIBDIR"

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

# Copie récursivement une bibliothèque et ses dépendances.
collect() {
    local queue=("$@")

    while [ ${#queue[@]} -gt 0 ]; do
        local lib="${queue[0]}"
        queue=("${queue[@]:1}")

        [ -n "${SEEN[$lib]:-}" ] && continue
        SEEN[$lib]=1

        is_excluded "$lib" && continue
        [ -f "$lib" ] || continue

        local target="$LIBDIR/$(basename "$lib")"
        if [ ! -e "$target" ]; then
            cp -L "$lib" "$target"
        fi

        while IFS= read -r dep; do
            [ -n "$dep" ] && queue+=("$dep")
        done < <(ldd "$lib" 2>/dev/null | awk '{print $3}' | grep '^/' || true)
    done
}

echo "GTK : collecte des dépendances…"

for root in \
    "$SYSTEM_LIB/libgtk-4.so.1" \
    "$SYSTEM_LIB/libadwaita-1.so.0" \
    "$SYSTEM_LIB/libgtksourceview-5.so.0" \
    "$SYSTEM_LIB/libvte-2.91-gtk4.so.0" \
    "$SYSTEM_LIB/gdk-pixbuf-2.0/gdk-pixbuf-query-loaders"; do
    if [ -f "$root" ]; then
        collect "$root"
    else
        echo "  absent : $root" >&2
    fi
done

# Loaders GdkPixbuf (SVG, PNG, JPEG…) et leurs dépendances.
LOADER_DIR="$SYSTEM_LIB/gdk-pixbuf-2.0/2.10.0/loaders"
DEST_LOADERS="$LIBDIR/gdk-pixbuf-2.0/2.10.0/loaders"
mkdir -p "$DEST_LOADERS"

if [ -d "$LOADER_DIR" ]; then
    for loader in "$LOADER_DIR"/*.so; do
        [ -f "$loader" ] || continue
        cp -L "$loader" "$DEST_LOADERS/"
        collect "$loader"
    done
fi

# Outil de génération du cache de loaders (exécuté au lancement).
if [ -x "$SYSTEM_LIB/gdk-pixbuf-2.0/gdk-pixbuf-query-loaders" ]; then
    cp -L "$SYSTEM_LIB/gdk-pixbuf-2.0/gdk-pixbuf-query-loaders" \
        "$LIBDIR/gdk-pixbuf-2.0/gdk-pixbuf-query-loaders"
fi

# Schémas GSettings.
SCHEMA_SRC="/usr/share/glib-2.0/schemas"
SCHEMA_DEST="$APPDIR/usr/share/glib-2.0/schemas"
mkdir -p "$SCHEMA_DEST"
cp -f "$SCHEMA_SRC"/*.gschema.xml "$SCHEMA_DEST/" 2>/dev/null || true

if command -v glib-compile-schemas >/dev/null 2>&1; then
    glib-compile-schemas "$SCHEMA_DEST"
else
    echo "  glib-compile-schemas absent : schémas non compilés" >&2
fi

# Thèmes d'icônes.
for theme in Adwaita hicolor; do
    if [ -d "/usr/share/icons/$theme" ]; then
        rm -rf "$APPDIR/usr/share/icons/$theme"
        cp -r "/usr/share/icons/$theme" "$APPDIR/usr/share/icons/"
    fi
done

echo "GTK embarqué : $(du -sh "$LIBDIR" | cut -f1) de bibliothèques"
