#!/usr/bin/env bash
#
# Vérifie que le Flatpak est installé et démarre en mode automatique.

set -euo pipefail

if ! flatpak info --user com.cyonima.Ide >/dev/null 2>&1; then
    echo "ÉCHEC : com.cyonima.Ide n'est pas installé"
    echo "Construire avec : flatpak-builder --user --install --force-clean build-dir packaging/flatpak/com.cyonima.Ide.json"
    exit 1
fi

OUTPUT="$(timeout 60 flatpak run --user com.cyonima.Ide --auto 2>&1 | tail -1)"

case "$OUTPUT" in
    "Fichier : /tmp/cyonima_ide_demo.php"*)
        echo "Flatpak OK"
        echo "$OUTPUT"
        ;;
    *)
        echo "ÉCHEC : sortie inattendue"
        echo "$OUTPUT"
        exit 1
        ;;
esac
