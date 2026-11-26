#!/usr/bin/env bash
#
# Construit un Flatpak pour un projet compilé en natif.
#
# Usage :
#   build-native-project-flatpak.sh PROJECT BINARY NAME SLUG APP_ID VERSION [OUTPUT]
#
# CYONIMA_NATIVE_LIBS peut contenir une liste de sonames à embarquer dans
# /app/lib/<slug> (ex. « libssh2.so.1 »).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PROJECT="${1:?Usage : build-native-project-flatpak.sh PROJECT BINARY NAME SLUG APP_ID VERSION [OUTPUT]}"
BINARY="${2:?binaire requis}"
NAME="${3:-Application}"
SLUG="${4:-application}"
APP_ID="${5:-com.example.application}"
VERSION="${6:-0.1.0}"
OUTPUT="${7:-}"
PHP_CLI="${PHP_CLI:-php}"

PROJECT="$(cd "$PROJECT" && pwd)"
BINARY="$(cd "$(dirname "$BINARY")" && pwd)/$(basename "$BINARY")"

if [ ! -x "$BINARY" ]; then
    echo "Binaire introuvable ou non exécutable : $BINARY" >&2
    exit 1
fi

if ! command -v flatpak-builder >/dev/null 2>&1; then
    echo "flatpak-builder introuvable." >&2
    exit 1
fi

if ! flatpak info org.gnome.Sdk//49 >/dev/null 2>&1; then
    echo "SDK Flatpak org.gnome.Sdk//49 absent." >&2
    exit 1
fi

if [ -z "$OUTPUT" ]; then
    OUTPUT="$PROJECT/dist/${SLUG}-${VERSION}-x86_64.flatpak"
fi

mkdir -p "$(dirname "$OUTPUT")"
OUTPUT="$(cd "$(dirname "$OUTPUT")" && pwd)/$(basename "$OUTPUT")"

WORK="$(mktemp -d "${TMPDIR:-/tmp}/cyonima-native-flatpak-XXXXXX")"
CONTEXT="$WORK/context"
trap 'rm -rf "$WORK"' EXIT

mkdir -p "$CONTEXT/libs"
install -m 0755 "$BINARY" "$CONTEXT/app-bin"

ICON="$PROJECT/assets/icon.svg"
if [ ! -f "$ICON" ]; then
    ICON="$ROOT/packaging/icon/com.cyonima.Ide.svg"
fi

cat > "$CONTEXT/app" <<EOF
#!/bin/sh
set -eu
export LD_LIBRARY_PATH="/app/lib/$SLUG\${LD_LIBRARY_PATH:+:\$LD_LIBRARY_PATH}"
exec /app/bin/$SLUG.bin "\$@"
EOF
chmod 0755 "$CONTEXT/app"

cat > "$CONTEXT/app.desktop" <<EOF
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

install -m 0644 "$ICON" "$CONTEXT/icon.svg"

LIB_ARGS=()

for soname in ${CYONIMA_NATIVE_LIBS:-}; do
    path="$(ldconfig -p 2>/dev/null | awk -v name="$soname" '$1 == name { print $NF; exit }')"

    if [ -z "$path" ] || [ ! -f "$path" ]; then
        echo "Bibliothèque native introuvable : $soname" >&2
        exit 1
    fi

    base="$(basename "$path")"
    cp -L "$path" "$CONTEXT/libs/$base"
    LIB_ARGS+=("$base")
done

"$PHP_CLI" "$ROOT/packaging/flatpak/native-project-manifest.php" \
    "$CONTEXT/manifest.json" "$APP_ID" "$SLUG" "${LIB_ARGS[@]}"

flatpak-builder \
    --force-clean \
    --disable-rofiles-fuse \
    --repo="$WORK/repo" \
    "$WORK/build" \
    "$CONTEXT/manifest.json"

rm -f "$OUTPUT"
flatpak build-bundle "$WORK/repo" "$OUTPUT" "$APP_ID" master

echo "Flatpak natif : $OUTPUT ($(du -h "$OUTPUT" | cut -f1))"
