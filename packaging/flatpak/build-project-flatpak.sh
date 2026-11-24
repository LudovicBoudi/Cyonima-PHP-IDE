#!/usr/bin/env bash
#
# Construit un bundle Flatpak autonome pour un projet Cyonima.
#
# Usage : build-project-flatpak.sh /chemin/du/projet [sortie.flatpak]
#
# Prérequis : flatpak-builder, le SDK GNOME, et les sources PHP (téléchargées
# par flatpak-builder si nécessaire).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PROJECT="${1:?Usage : build-project-flatpak.sh /chemin/du/projet [sortie.flatpak]}"
PROJECT="$(cd "$PROJECT" && pwd)"
PHP_CLI="${PHP_CLI:-php}"

if ! command -v flatpak-builder >/dev/null 2>&1; then
    echo "flatpak-builder introuvable." >&2
    exit 1
fi

if ! flatpak info org.gnome.Sdk//49 >/dev/null 2>&1; then
    echo "SDK Flatpak org.gnome.Sdk//49 absent." >&2
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

OUTPUT="${2:-$PROJECT/dist/${SLUG}-${VERSION}-x86_64.flatpak}"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/cyonima-flatpak-XXXXXX")"
CONTEXT="$WORK/context"
trap 'rm -rf "$WORK"' EXIT

mkdir -p "$CONTEXT/app" "$CONTEXT/cyonima" "$(dirname "$OUTPUT")"

cp -a "$PROJECT/." "$CONTEXT/app/"
cp -r "$ROOT/autoload.php" "$ROOT/src" "$CONTEXT/cyonima/"

ICON="$PROJECT/assets/icon.svg"
if [ ! -f "$ICON" ]; then
    ICON="$ROOT/packaging/icon/com.cyonima.Ide.svg"
fi
cp -f "$ICON" "$CONTEXT/icon.svg"

"$PHP_CLI" "$ROOT/packaging/flatpak/project-manifest.php" \
    "$CONTEXT/manifest.json" "$NAME" "$SLUG" "$APP_ID" "$ENTRY"

flatpak-builder \
    --force-clean \
    --disable-rofiles-fuse \
    --repo="$WORK/repo" \
    "$WORK/build" \
    "$CONTEXT/manifest.json"

flatpak build-bundle "$WORK/repo" "$OUTPUT" "$APP_ID" master

echo "Flatpak : $OUTPUT ($(du -h "$OUTPUT" | cut -f1))"
