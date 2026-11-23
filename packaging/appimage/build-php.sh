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
    --enable-simplexml \
    --enable-dom \
    --with-libxml

make -j"$(nproc)"
make install INSTALL_ROOT="$OUT"

strip "$OUT/usr/bin/php"

echo "PHP embarqué prêt : $OUT/usr/bin/php"
