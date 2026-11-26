<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Named;

class User implements Named
{
    public string $label;

    public function __construct(string $label)
    {
        $this->label = $label;
    }

    public function name(): string
    {
        return $this->label;
    }
}
