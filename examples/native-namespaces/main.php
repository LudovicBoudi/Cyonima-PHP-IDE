<?php

declare(strict_types=1);

/**
 * Exemple de compilation multi-fichiers avec namespaces.
 *
 *   bin/cyonima-compile --project=examples/native-namespaces \
 *       --php=examples/native-namespaces/main.php --emit=elf
 *   ./examples/native-namespaces/dist/main
 */

use App\Models\User;
use App\Services\UserService;

$user = new User('alice');
$service = new UserService();

echo $service->describe($user), "\n";
