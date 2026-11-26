<?php

declare(strict_types=1);

/**
 * Exemple de validation typée.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-validation.php --emit=elf
 *   ./dist/native-validation
 */

namespace App;

use Cyonima\Validation\Email;
use Cyonima\Validation\Integer;
use Cyonima\Validation\MinLength;
use Cyonima\Validation\Required;
use Cyonima\Validation\ValidationException;
use Cyonima\Validation\Validator;

$validator = new Validator();
$validator->add('name', new Required());
$validator->add('name', new MinLength(3));
$validator->add('email', new Email());
$validator->add('age', new Integer());

$input = [
    'name' => 'Al',
    'email' => 'bad',
    'age' => 'nope',
];

$errors = $validator->validate($input);
echo count($errors), " erreur(s)\n";

foreach ($errors as $field => $message) {
    echo $field, ': ', $message, "\n";
}

$valid = [
    'name' => 'Alice',
    'email' => 'alice@example.com',
    'age' => '30',
];

try {
    $validator->validateOrFail($valid);
    echo "valide\n";
} catch (ValidationException $error) {
    echo 'invalide: ', $error->getMessage(), "\n";
}
