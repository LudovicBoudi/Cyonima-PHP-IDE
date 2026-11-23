#!/usr/bin/env bash
#
# Construit un bundle portable (tar.gz) de Cyonima.
# Le bundle dépend du PHP et de GTK4 du système ; voir packaging/README.md.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${VERSION:-0.1.0}"
NAME="cyonima-${VERSION}"
DEST="$ROOT/dist/$NAME"
ARCHIVE="$ROOT/dist/${NAME}-linux-x86_64.tar.gz"

rm -rf "$DEST" "$ARCHIVE"
mkdir -p "$DEST"

cp -r \
    "$ROOT/autoload.php" \
    "$ROOT/bin" \
    "$ROOT/src" \
    "$ROOT/examples" \
    "$ROOT/spec" \
    "$ROOT/composer.json" \
    "$ROOT/README.md" \
    "$ROOT/LICENSE" \
    "$DEST/"

mkdir -p "$DEST/share/applications" "$DEST/share/icons/hicolor/scalable/apps"
cp "$ROOT/packaging/com.cyonima.Ide.desktop" "$DEST/share/applications/"
cp "$ROOT/packaging/icon/com.cyonima.Ide.svg" "$DEST/share/icons/hicolor/scalable/apps/"

install -m 0755 "$ROOT/packaging/AppRun" "$DEST/AppRun"
install -m 0755 "$ROOT/bin/cyonima" "$DEST/bin/cyonima"
install -m 0644 "$ROOT/bin/cyonima.php" "$DEST/bin/cyonima.php"

# Retire les artefacts de développement éventuellement copiés.
rm -rf "$DEST/bin/cyonima-gen"

tar -C "$ROOT/dist" -czf "$ARCHIVE" "$NAME"

echo "Bundle  : $DEST"
echo "Archive : $ARCHIVE ($(du -h "$ARCHIVE" | cut -f1))"
