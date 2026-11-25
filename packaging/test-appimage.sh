#!/usr/bin/env bash
#
# Vérifie l'AppImage : lancement autonome, PHP embarqué, scénario automatique.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APPIMAGE="${APPIMAGE:-$(ls -t "$ROOT"/dist/Cyonima-*-x86_64.AppImage 2>/dev/null | head -1 || true)}"

if [ -z "${APPIMAGE:-}" ] || [ ! -x "$APPIMAGE" ]; then
    echo "ÉCHEC : aucune AppImage trouvée dans dist/" >&2
    echo "Lancer : packaging/appimage/build-appimage.sh" >&2
    exit 1
fi

echo "Test de $APPIMAGE"

# 1) --version sans environnement utilisateur (pas de PHP de l'hôte dans PATH).
VERSION_OUTPUT="$(env -i HOME="$HOME" DISPLAY="${DISPLAY:-:0}" \
    XAUTHORITY="${XAUTHORITY:-}" APPIMAGE_EXTRACT_AND_RUN=1 \
    "$APPIMAGE" --version 2>&1 | tail -1)"

if [ "$VERSION_OUTPUT" != "Cyonima IDE 0.1.0" ]; then
    echo "ÉCHEC : --version → ${VERSION_OUTPUT:-<vide>}" >&2
    exit 1
fi

# 2) Scénario automatique complet (GTK système requis).
OUTPUT="$(APPIMAGE_EXTRACT_AND_RUN=1 timeout 60 "$APPIMAGE" --auto 2>&1 | tail -1)"

case "$OUTPUT" in
    "Fichier : /tmp/cyonima_ide_demo.php"*)
        echo "AppImage OK"
        echo "$OUTPUT"
        ;;
    *)
        echo "ÉCHEC : sortie inattendue" >&2
        echo "$OUTPUT" >&2
        exit 1
        ;;
esac
