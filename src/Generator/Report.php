<?php

declare(strict_types=1);

namespace Cyonima\Generator;

final class Report
{
    /** @var list<string> */
    public array $files = [];

    /** @var list<string> */
    public array $skipped = [];

    public int $classes = 0;
    public int $methods = 0;
    public int $signals = 0;
    public int $enums = 0;

    public function render(): string
    {
        $lines = [
            sprintf(
                'Généré : %d classes, %d méthodes, %d signaux, %d enums, %d fichiers.',
                $this->classes,
                $this->methods,
                $this->signals,
                $this->enums,
                count($this->files),
            ),
        ];

        if ($this->skipped !== []) {
            $lines[] = sprintf('%d méthode(s) ignorée(s) :', count($this->skipped));
            foreach ($this->skipped as $skip) {
                $lines[] = '  - ' . $skip;
            }
        }

        return implode("\n", $lines) . "\n";
    }
}
