<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User as Member;

class UserService
{
    public function describe(Member $user): string
    {
        return 'user:' . $user->name();
    }
}
