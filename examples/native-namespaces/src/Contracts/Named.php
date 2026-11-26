<?php

declare(strict_types=1);

namespace App\Contracts;

interface Named
{
    public function name(): string;
}
