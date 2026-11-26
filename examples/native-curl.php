<?php

declare(strict_types=1);

/**
 * Exemple de module natif libcurl compilé.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-curl.php \
 *       --emit=elf --module=curl
 *   ./dist/native-curl
 */

$curl = curl_init('https://example.com');
curl_set_method($curl, 'GET');
curl_set_header($curl, 'Accept: text/html');

if (!curl_perform($curl)) {
    echo 'erreur: ', curl_error($curl), "\n";
} else {
    echo 'statut: ', curl_status($curl), "\n";
    echo curl_body($curl);
}

curl_close($curl);
