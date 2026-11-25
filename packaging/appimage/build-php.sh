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
DEPS="$BUILD/deps"

mkdir -p "$SRC" "$DEPS"

if [ ! -f "$SRC/php-$PHP_VERSION.tar.xz" ]; then
    curl -fsSL -o "$SRC/php-$PHP_VERSION.tar.xz" \
        "https://www.php.net/distributions/php-$PHP_VERSION.tar.xz"
fi

echo "$PHP_SHA256  $SRC/php-$PHP_VERSION.tar.xz" | sha256sum -c -

# En-têtes libcurl pour compiler l'extension curl. Le paquet runtime est déjà
# présent sur l'hôte ; on n'utilise que les en-têtes/le curl-config de -dev.
if [ ! -f "$DEPS/curl/usr/include/curl/curl.h" ] && command -v apt-get >/dev/null 2>&1; then
    mkdir -p "$DEPS/curl-deb"
    if (cd "$DEPS/curl-deb" && apt-get download libcurl4-openssl-dev >/dev/null 2>&1); then
        deb="$(ls "$DEPS/curl-deb"/libcurl4-openssl-dev_*.deb 2>/dev/null | head -1 || true)"
        [ -n "$deb" ] && dpkg-deb -x "$deb" "$DEPS/curl"
    fi
fi

# En-têtes et bibliothèque libzip pour l'extension zip.
if [ ! -f "$DEPS/zip/usr/include/zip.h" ] && command -v apt-get >/dev/null 2>&1; then
    mkdir -p "$DEPS/zip-deb"
    if (cd "$DEPS/zip-deb" && apt-get download libzip-dev libzip4t64 >/dev/null 2>&1); then
        for deb in "$DEPS/zip-deb"/libzip*.deb; do
            [ -f "$deb" ] && dpkg-deb -x "$deb" "$DEPS/zip"
        done
    fi
fi

rm -rf "$SRC/php-$PHP_VERSION" "$OUT"
tar -C "$SRC" -xf "$SRC/php-$PHP_VERSION.tar.xz"

cd "$SRC/php-$PHP_VERSION"

# Les .deb de développement sont extraits hors préfixe : fournir les chemins
# directement à configure plutôt que de dépendre de pkg-config.
export PKG_CONFIG_PATH="$DEPS/curl/usr/lib/x86_64-linux-gnu/pkgconfig:$DEPS/zip/usr/lib/x86_64-linux-gnu/pkgconfig:${PKG_CONFIG_PATH:-}"
export CPPFLAGS="-I$DEPS/curl/usr/include/x86_64-linux-gnu -I$DEPS/zip/usr/include ${CPPFLAGS:-}"
export LDFLAGS="-L$DEPS/curl/usr/lib/x86_64-linux-gnu -L$DEPS/zip/usr/lib/x86_64-linux-gnu ${LDFLAGS:-}"
# Le paquet -dev ne fournit pas le fichier runtime : pointer le libcurl hôte.
ln -sf /lib/x86_64-linux-gnu/libcurl.so.4 "$DEPS/curl/usr/lib/x86_64-linux-gnu/libcurl.so" 2>/dev/null || true
export CURL_CFLAGS="-I$DEPS/curl/usr/include/x86_64-linux-gnu"
export CURL_LIBS="-L$DEPS/curl/usr/lib/x86_64-linux-gnu -lcurl"

./configure \
    --prefix=/usr \
    --disable-all \
    --enable-ctype \
    --enable-cli \
    --disable-cgi \
    --disable-phpdbg \
    --with-ffi \
    --with-openssl \
    --enable-sockets \
    --enable-pcntl \
    --enable-posix \
    --with-curl="$DEPS/curl/usr" \
    --enable-intl \
    --enable-pdo \
    --with-pdo-sqlite \
    --with-sqlite3 \
    --with-zip \
    --enable-gd \
    --with-jpeg \
    --with-freetype \
    --with-webp \
    --enable-simplexml \
    --enable-dom \
    --with-libxml

make -j"$(nproc)"
make install INSTALL_ROOT="$OUT"

strip "$OUT/usr/bin/php"

# libzip est lié dynamiquement par l'extension zip ; l'installer à côté de PHP
# pour que le bundle AppImage puisse le collecter avec ldd.
if [ -d "$DEPS/zip/usr/lib/x86_64-linux-gnu" ]; then
    mkdir -p "$OUT/usr/lib"
    for lib in "$DEPS/zip/usr/lib/x86_64-linux-gnu"/libzip.so.4*; do
        [ -e "$lib" ] && cp -L "$lib" "$OUT/usr/lib/"
    done
fi

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
