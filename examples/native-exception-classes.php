<?php

declare(strict_types=1);

/**
 * Exemple de hiérarchie d’exceptions et finally.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-exception-classes.php --emit=elf
 *   ./dist/native-exception-classes
 */

class AppException extends RuntimeException
{
}

function load(bool $fail): string
{
    try {
        if ($fail) {
            throw new AppException('chargement impossible');
        }

        return 'ok';
    } catch (AppException $error) {
        return 'app: ' . $error->getMessage();
    } finally {
        echo "cleanup\n";
    }
}

echo load(false), "\n";
echo load(true), "\n";

try {
    throw new RuntimeException('runtime');
} catch (AppException $error) {
    echo 'app', "\n";
} catch (RuntimeException $error) {
    echo 'runtime: ', $error->getMessage(), "\n";
} finally {
    echo "done\n";
}
