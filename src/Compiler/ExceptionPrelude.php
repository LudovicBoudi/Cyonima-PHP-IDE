<?php

declare(strict_types=1);

namespace Cyonima\Compiler;

/**
 * Classes d’exception globales injectées dans chaque programme compilé.
 */
final class ExceptionPrelude
{
    public const SOURCE = <<<'PHP'
        <?php

        class Exception
        {
            public string $message;

            public function __construct(string $message)
            {
                $this->message = $message;
            }

            public function getMessage(): string
            {
                return $this->message;
            }
        }

        class RuntimeException extends Exception
        {
        }

        class LogicException extends Exception
        {
        }

        class InvalidArgumentException extends Exception
        {
        }

        class DomainException extends Exception
        {
        }
        PHP;
}
