#!/usr/bin/env bash
#
# Construit le PHP embarqué dans l'AppImage (FFI, SimpleXML, DOM statiques).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_VERSION="8.3.14"
PHP_SHA256="58b4cb9019bf70c0cbcdb814c7df79b9065059d14cf7dbf48d971f8e56ae9be7"
BUILD="$ROOT/build/appimage-php"
SRC="$BUILD/src"
OUT="$BUILD/php"

mkdir -p "$SRC"

if [ ! -f "$SRC/php-$PHP_VERSION.tar.xz" ]; then
    curl -fsSL -o "$SRC/php-$PHP_VERSION.tar.xz" \
        "https://www.php.net/distributions/php-$PHP_VERSION.tar.xz"
fi

echo "$PHP_SHA256  $SRC/php-$PHP_VERSION.tar.xz" | sha256sum -c -

rm -rf "$SRC/php-$PHP_VERSION" "$OUT"
tar -C "$SRC" -xf "$SRC/php-$PHP_VERSION.tar.xz"

cd "$SRC/php-$PHP_VERSION"

./configure \
    --prefix=/usr \
    --disable-all \
    --enable-cli \
    --disable-cgi \
    --disable-phpdbg \
    --with-ffi \
    --with-openssl \
    --enable-sockets \
    --enable-pcntl \
    --enable-posix \
    --enable-simplexml \
    --enable-dom \
    --with-libxml

make -j"$(nproc)"
make install INSTALL_ROOT="$OUT"

strip "$OUT/usr/bin/php"

# --- Extension SSH2 -------------------------------------------------------
# L'extension PECL ssh2 n'est pas livrée avec PHP. Le paquet Ubuntu fournit un
# binaire ABI-compatible avec PHP 8.3 ; on l'ajoute au runtime embarqué.
EXT_DIR="$OUT/usr/lib/php/extensions/no-debug-non-zts-20230831"
mkdir -p "$EXT_DIR"

if command -v apt-get >/dev/null 2>&1 && command -v dpkg-deb >/dev/null 2>&1; then
    SSH2_TMP="$(mktemp -d)"
    if (cd "$SSH2_TMP" && apt-get download php8.3-ssh2 >/dev/null 2>&1); then
        SSH2_DEB="$(ls "$SSH2_TMP"/php8.3-ssh2_*.deb 2>/dev/null | head -1 || true)"
        if [ -n "$SSH2_DEB" ]; then
            dpkg-deb -x "$SSH2_DEB" "$SSH2_TMP/root"
            SSH2_SO="$(find "$SSH2_TMP/root/usr/lib/php" -name ssh2.so -print -quit 2>/dev/null || true)"
            if [ -n "$SSH2_SO" ]; then
                cp "$SSH2_SO" "$EXT_DIR/ssh2.so"
            fi
        fi
    else
        echo "Attention : paquet php8.3-ssh2 indisponible, ssh2 non embarqué." >&2
    fi
    rm -rf "$SSH2_TMP"
else
    echo "Attention : apt-get/dpkg-deb absents, ssh2 non embarqué." >&2
fi

echo "PHP embarqué prêt : $OUT/usr/bin/php"
