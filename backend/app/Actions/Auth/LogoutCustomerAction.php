<?php

namespace App\Actions\Auth;

use App\Models\User;

class LogoutCustomerAction
{
    public function handle(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }
}
