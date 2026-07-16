<?php

namespace App\Actions\Customer;

use App\Models\CustomerProfile;
use App\Models\User;

class GetCustomerProfileAction
{
    public function handle(User $user): ?CustomerProfile
    {
        return $user->customerProfile()->first();
    }
}
