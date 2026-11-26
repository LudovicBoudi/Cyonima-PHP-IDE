<?php

declare(strict_types=1);

namespace Cyonima\Compiler;

/**
 * Validation typée : règles chaînes, email, entier, liste, bornes.
 */
final class ValidationPrelude
{
    public const SOURCE = <<<'PHP'
        <?php

        namespace Cyonima\Validation;

        use Exception;

        interface Rule
        {
            public function validate(string $value): ?string;
        }

        final class Required implements Rule
        {
            public function validate(string $value): ?string
            {
                if ($value === '') {
                    return 'est requis';
                }

                return null;
            }
        }

        final class MinLength implements Rule
        {
            public int $length;

            public function __construct(int $length)
            {
                $this->length = $length;
            }

            public function validate(string $value): ?string
            {
                if ($value !== '' && strlen($value) < $this->length) {
                    return 'doit contenir au moins ' . strval($this->length) . ' caractères';
                }

                return null;
            }
        }

        final class MaxLength implements Rule
        {
            public int $length;

            public function __construct(int $length)
            {
                $this->length = $length;
            }

            public function validate(string $value): ?string
            {
                if (strlen($value) > $this->length) {
                    return 'ne doit pas dépasser ' . strval($this->length) . ' caractères';
                }

                return null;
            }
        }

        final class Email implements Rule
        {
            public function validate(string $value): ?string
            {
                if ($value === '') {
                    return null;
                }

                if (!str_contains($value, '@') || !str_contains($value, '.')) {
                    return 'doit être une adresse email valide';
                }

                return null;
            }
        }

        final class Integer implements Rule
        {
            public function validate(string $value): ?string
            {
                if ($value === '') {
                    return null;
                }

                if (!is_integer($value)) {
                    return 'doit être un entier';
                }

                return null;
            }
        }

        final class Numeric implements Rule
        {
            public function validate(string $value): ?string
            {
                if ($value === '') {
                    return null;
                }

                if (!is_numeric($value)) {
                    return 'doit être un nombre';
                }

                return null;
            }
        }

        final class MinValue implements Rule
        {
            public int $minimum;

            public function __construct(int $minimum)
            {
                $this->minimum = $minimum;
            }

            public function validate(string $value): ?string
            {
                if ($value !== '' && intval($value) < $this->minimum) {
                    return 'doit être supérieur ou égal à ' . strval($this->minimum);
                }

                return null;
            }
        }

        final class MaxValue implements Rule
        {
            public int $maximum;

            public function __construct(int $maximum)
            {
                $this->maximum = $maximum;
            }

            public function validate(string $value): ?string
            {
                if ($value !== '' && intval($value) > $this->maximum) {
                    return 'doit être inférieur ou égal à ' . strval($this->maximum);
                }

                return null;
            }
        }

        final class In implements Rule
        {
            /** @var array<string> */
            public array $values;

            /** @param array<string> $values */
            public function __construct(array $values)
            {
                $this->values = $values;
            }

            public function validate(string $value): ?string
            {
                if ($value === '') {
                    return null;
                }

                foreach ($this->values as $allowed) {
                    if ($allowed === $value) {
                        return null;
                    }
                }

                return 'doit être une valeur autorisée';
            }
        }

        final class RuleEntry
        {
            public string $field;
            public Rule $rule;

            public function __construct(string $field, Rule $rule)
            {
                $this->field = $field;
                $this->rule = $rule;
            }
        }

        final class ValidationException extends Exception
        {
            /** @var array<string,string> */
            public array $errors;

            /** @param array<string,string> $errors */
            public function __construct(array $errors)
            {
                $this->errors = $errors;
                parent::__construct($this->summarize($errors));
            }

            /** @return array<string,string> */
            public function getErrors(): array
            {
                return $this->errors;
            }

            /** @param array<string,string> $errors */
            private function summarize(array $errors): string
            {
                $summary = '';
                $first = true;

                foreach ($errors as $message) {
                    if (!$first) {
                        $summary = $summary . '; ';
                    }

                    $summary = $summary . $message;
                    $first = false;
                }

                return $summary;
            }
        }

        final class Validator
        {
            /** @var array<RuleEntry> */
            public array $rules;

            public function add(string $field, Rule $rule): void
            {
                $rules = $this->rules;
                $rules[] = new RuleEntry($field, $rule);
                $this->rules = $rules;
            }

            /**
             * @param array<string,string> $data
             * @return array<string,string>
             */
            public function validate(array $data): array
            {
                $errors = $this->emptyErrors();
                $rules = $this->rules;

                foreach ($rules as $entry) {
                    $value = '';

                    if (isset($data[$entry->field])) {
                        $value = $data[$entry->field];
                    }

                    $message = $entry->rule->validate($value);

                    if ($message !== null) {
                        if (!isset($errors[$entry->field])) {
                            $errors[$entry->field] = $entry->field . ' ' . $message;
                        }
                    }
                }

                return $errors;
            }

            /** @param array<string,string> $data */
            public function validateOrFail(array $data): void
            {
                $errors = $this->validate($data);

                if (count($errors) > 0) {
                    throw new ValidationException($errors);
                }
            }

            /** @return array<string,string> */
            private function emptyErrors(): array
            {
                return [];
            }
        }
        PHP;
}
