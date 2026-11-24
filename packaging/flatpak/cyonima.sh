#!/bin/sh
#
# Lanceur installé dans /app/bin par le Flatpak. Le runtime GNOME fournit
# GTK4, libadwaita, GtkSourceView et VTE ; PHP est construit par le manifeste.

# VTE peut être installé dans /app/lib64 selon la version de meson.
export LD_LIBRARY_PATH="/app/lib:/app/lib64${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"

exec /app/bin/php -d ffi.enable=1 -d extension=ssh2 /app/share/cyonima/bin/cyonima.php "$@"
