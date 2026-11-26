<?php

declare(strict_types=1);

/**
 * Exemple de commandes console natives.
 *
 *   bin/cyonima-compile --project=. --php=examples/native-console.php --emit=elf
 *   ./dist/native-console list
 *   ./dist/native-console greet Alice
 *   ./dist/native-console greet Alice --shout
 */

namespace App;

use Cyonima\Console\Application;
use Cyonima\Console\Command;
use Cyonima\Console\Input;
use Cyonima\Console\Output;

final class GreetCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('greet');
        $this->setDescription('Salue une personne');
        $this->addArgument('name', true, 'Nom à saluer');
        $this->addOption('shout', 's', false, 'Ajoute un point d’exclamation');
    }

    public function run(Input $input, Output $output): int
    {
        $name = $input->argument('name', 'world');
        $message = 'Hello ' . $name;

        if ($input->hasOption('shout')) {
            $message = $message . '!';
        }

        $output->writeln($message);

        return 0;
    }
}

$app = new Application('cyonima-demo', '1.0.0');
$app->add(new GreetCommand());

return $app->run(cli_args());
