#!/usr/bin/env bash
#
# Vérifie le bundle portable de bout en bout : construit, extrait dans un
# répertoire temporaire, lance la démo automatique et contrôle la sortie.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="test-$$"

VERSION="$VERSION" "$ROOT/packaging/build-portable.sh" >/dev/null

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP" "$ROOT/dist/cyonima-$VERSION" "$ROOT/dist/cyonima-$VERSION-linux-x86_64.tar.gz"' EXIT

tar -xzf "$ROOT/dist/cyonima-$VERSION-linux-x86_64.tar.gz" -C "$TMP"

BIN="$TMP/cyonima-$VERSION/bin/cyonima"

# Isole le répertoire temporaire de la démo (sys_get_temp_dir côté PHP).
mkdir -p "$TMP/tmp"
export TMPDIR="$TMP/tmp"
EXPECTED="Fichier : $TMP/tmp/cyonima_ide_demo.php"

"$BIN" --version | grep -q "Cyonima" || {
    echo "ÉCHEC : --version"
    exit 1
}

OUTPUT="$("$BIN" --auto 2>&1 | grep "Fichier :" || true)"

case "$OUTPUT" in
    "$EXPECTED"*) ;;
    *)
        echo "ÉCHEC : fichier inattendu"
        echo "attendu : $EXPECTED"
        echo "obtenu  : ${OUTPUT:-<vide>}"
        exit 1
        ;;
esac

echo "Portable OK"
echo "$OUTPUT"
