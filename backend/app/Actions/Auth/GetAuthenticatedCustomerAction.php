<?php

namespace App\Actions\Auth;

use App\Models\User;

class GetAuthenticatedCustomerAction
{
    public function handle(User $user): User
    {
        return $user;
    }
}
