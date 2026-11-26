<?php

declare(strict_types=1);

/**
 * Exemple de module natif libssh2 compilé.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-ssh.php \
 *       --emit=elf --module=ssh2
 *   ./dist/native-ssh
 */

$ssh = ssh2_connect('example.org', 22);

if ($ssh === 0) {
    echo 'connexion: ', ssh2_error(0), "\n";
} else {
    if (ssh2_auth_password($ssh, 'demo', 'secret')) {
        echo ssh2_exec($ssh, 'uname -a');
        echo 'statut: ', ssh2_exit_status($ssh), "\n";
    } else {
        echo 'auth: ', ssh2_error($ssh), "\n";
    }

    ssh2_disconnect($ssh);
}
