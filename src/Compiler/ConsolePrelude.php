<?php

declare(strict_types=1);

namespace Cyonima\Compiler;

/**
 * Mini-framework console compilé : commandes, arguments, options, aide.
 */
final class ConsolePrelude
{
    public const SOURCE = <<<'PHP'
        <?php

        namespace Cyonima\Console;

        use Exception;
        use RuntimeException;

        final class Argument
        {
            public string $name;
            public bool $required;
            public string $description;

            public function __construct(string $name, bool $required, string $description)
            {
                $this->name = $name;
                $this->required = $required;
                $this->description = $description;
            }
        }

        final class Option
        {
            public string $name;
            public string $shortcut;
            public bool $takesValue;
            public string $description;

            public function __construct(string $name, string $shortcut, bool $takesValue, string $description)
            {
                $this->name = $name;
                $this->shortcut = $shortcut;
                $this->takesValue = $takesValue;
                $this->description = $description;
            }
        }

        final class Output
        {
            public function write(string $message): void
            {
                echo $message;
            }

            public function writeln(string $message): void
            {
                echo $message, "\n";
            }

            public function error(string $message): void
            {
                stderr_write($message . "\n");
            }
        }

        final class Input
        {
            public Command $command;

            /** @var array<string> */
            public array $positional;

            /** @var array<string,string> */
            public array $values;

            /** @var array<string,bool> */
            public array $flags;

            /**
             * @param array<string> $tokens
             */
            public function __construct(Command $command, array $tokens)
            {
                $this->command = $command;
                $positional = [];
                $values = [];
                $flags = [];
                $index = 0;
                $count = count($tokens);

                while ($index < $count) {
                    $token = $tokens[$index];

                    if (str_starts_with($token, '--')) {
                        $name = substr($token, 2);
                        $option = $this->findLong($command->optionDefinitions, $name);

                        if ($option === null) {
                            throw new RuntimeException('option inconnue : --' . $name);
                        }

                        if ($option->takesValue) {
                            $index = $index + 1;

                            if ($index >= $count) {
                                throw new RuntimeException('valeur manquante pour --' . $name);
                            }

                            $values[$option->name] = $tokens[$index];
                        } else {
                            $flags[$option->name] = true;
                        }
                    } elseif (str_starts_with($token, '-')) {
                        $shortcut = substr($token, 1);
                        $option = $this->findShort($command->optionDefinitions, $shortcut);

                        if ($option === null) {
                            throw new RuntimeException('option inconnue : -' . $shortcut);
                        }

                        if ($option->takesValue) {
                            $index = $index + 1;

                            if ($index >= $count) {
                                throw new RuntimeException('valeur manquante pour -' . $shortcut);
                            }

                            $values[$option->name] = $tokens[$index];
                        } else {
                            $flags[$option->name] = true;
                        }
                    } else {
                        $positional[] = $token;
                    }

                    $index = $index + 1;
                }

                $position = 0;

                foreach ($command->argumentDefinitions as $argument) {
                    if ($argument->required && $position >= count($positional)) {
                        throw new RuntimeException('argument manquant : ' . $argument->name);
                    }

                    $position = $position + 1;
                }

                $this->positional = $positional;
                $this->values = $values;
                $this->flags = $flags;
            }

            public function argument(string $name, string $default): string
            {
                $position = 0;
                $positional = $this->positional;

                foreach ($this->command->argumentDefinitions as $argument) {
                    if ($argument->name === $name) {
                        if ($position < count($positional)) {
                            return $positional[$position];
                        }

                        return $default;
                    }

                    $position = $position + 1;
                }

                return $default;
            }

            public function option(string $name, string $default): string
            {
                $values = $this->values;
                $flags = $this->flags;

                if (isset($values[$name])) {
                    return $values[$name];
                }

                if (isset($flags[$name])) {
                    return '1';
                }

                return $default;
            }

            public function hasOption(string $name): bool
            {
                $values = $this->values;
                $flags = $this->flags;

                return isset($values[$name]) || isset($flags[$name]);
            }

            /**
             * @param array<Option> $options
             */
            private function findLong(array $options, string $name): ?Option
            {
                foreach ($options as $option) {
                    if ($option->name === $name) {
                        return $option;
                    }
                }

                return null;
            }

            /**
             * @param array<Option> $options
             */
            private function findShort(array $options, string $shortcut): ?Option
            {
                foreach ($options as $option) {
                    if ($option->shortcut === $shortcut) {
                        return $option;
                    }
                }

                return null;
            }
        }

        abstract class Command
        {
            public string $name;
            public string $description;

            /** @var array<Argument> */
            public array $argumentDefinitions;

            /** @var array<Option> */
            public array $optionDefinitions;

            public function __construct()
            {
                $this->configure();
            }

            protected function configure(): void
            {
            }

            protected function setName(string $name): void
            {
                $this->name = $name;
            }

            protected function setDescription(string $description): void
            {
                $this->description = $description;
            }

            protected function addArgument(string $name, bool $required, string $description): void
            {
                $arguments = $this->argumentDefinitions;
                $arguments[] = new Argument($name, $required, $description);
                $this->argumentDefinitions = $arguments;
            }

            protected function addOption(string $name, string $shortcut, bool $takesValue, string $description): void
            {
                $options = $this->optionDefinitions;
                $options[] = new Option($name, $shortcut, $takesValue, $description);
                $this->optionDefinitions = $options;
            }

            abstract public function run(Input $input, Output $output): int;
        }

        final class Application
        {
            public string $name;
            public string $version;

            /** @var array<string,Command> */
            public array $commands;

            public function __construct(string $name, string $version)
            {
                $this->name = $name;
                $this->version = $version;
            }

            public function add(Command $command): void
            {
                $commands = $this->commands;

                if ($command->name === '') {
                    throw new RuntimeException('commande sans nom');
                }

                if (isset($commands[$command->name])) {
                    throw new RuntimeException('commande déjà définie : ' . $command->name);
                }

                $commands[$command->name] = $command;
                $this->commands = $commands;
            }

            /**
             * @param array<string> $argv
             */
            public function run(array $argv): int
            {
                $output = new Output();
                $tokens = $this->tokens($argv);
                $commands = $this->commands;

                if (count($tokens) === 0) {
                    $this->listCommands($output);

                    return 0;
                }

                $first = $tokens[0];

                if ($first === '--version' || $first === '-V') {
                    $output->writeln($this->name . ' ' . $this->version);

                    return 0;
                }

                if ($first === '--help' || $first === '-h') {
                    $this->applicationHelp($output);

                    return 0;
                }

                if ($first === 'list') {
                    $this->listCommands($output);

                    return 0;
                }

                if ($first === 'help') {
                    if (count($tokens) > 1) {
                        if (isset($commands[$tokens[1]])) {
                            $this->commandHelp($commands[$tokens[1]], $output);

                            return 0;
                        }
                    }

                    $this->applicationHelp($output);

                    return 0;
                }

                if (!isset($commands[$first])) {
                    $output->error('commande inconnue : ' . $first);

                    return 1;
                }

                $command = $commands[$first];

                if (count($tokens) > 1) {
                    if ($tokens[1] === '--help' || $tokens[1] === '-h') {
                        $this->commandHelp($command, $output);

                        return 0;
                    }
                }

                $arguments = [];
                $index = 1;

                while ($index < count($tokens)) {
                    $arguments[] = $tokens[$index];
                    $index = $index + 1;
                }

                try {
                    $input = new Input($command, $arguments);

                    return $command->run($input, $output);
                } catch (Exception $error) {
                    $output->error($error->getMessage());

                    return 1;
                }
            }

            /**
             * @param array<string> $argv
             * @return array<string>
             */
            private function tokens(array $argv): array
            {
                $tokens = [];
                $index = 1;

                while ($index < count($argv)) {
                    $tokens[] = $argv[$index];
                    $index = $index + 1;
                }

                return $tokens;
            }

            private function applicationHelp(Output $output): void
            {
                $output->writeln($this->name . ' ' . $this->version);
                $output->writeln('');
                $output->writeln('Usage: ' . $this->name . ' <commande> [arguments] [options]');
                $output->writeln('');
                $output->writeln('Commandes :');
                $this->listCommandLines($output);
                $output->writeln('');
                $output->writeln('Options globales : --help, --version');
            }

            private function listCommands(Output $output): void
            {
                $output->writeln($this->name . ' ' . $this->version);
                $output->writeln('');
                $output->writeln('Commandes disponibles :');
                $this->listCommandLines($output);
            }

            private function listCommandLines(Output $output): void
            {
                $commands = $this->commands;

                foreach ($commands as $name => $command) {
                    $output->writeln('  ' . $name . '  ' . $command->description);
                }
            }

            private function commandHelp(Command $command, Output $output): void
            {
                $output->writeln('Usage: ' . $this->name . ' ' . $command->name . ' [arguments] [options]');
                $output->writeln('');
                $output->writeln($command->description);

                if (count($command->argumentDefinitions) > 0) {
                    $output->writeln('');
                    $output->writeln('Arguments :');

                    foreach ($command->argumentDefinitions as $argument) {
                        $suffix = '';

                        if ($argument->required) {
                            $suffix = ' (requis)';
                        }

                        $output->writeln('  ' . $argument->name . $suffix . '  ' . $argument->description);
                    }
                }

                if (count($command->optionDefinitions) > 0) {
                    $output->writeln('');
                    $output->writeln('Options :');

                    foreach ($command->optionDefinitions as $option) {
                        $label = '--' . $option->name;

                        if ($option->shortcut !== '') {
                            $label = $label . ', -' . $option->shortcut;
                        }

                        $output->writeln('  ' . $label . '  ' . $option->description);
                    }
                }
            }
        }
        PHP;
}
