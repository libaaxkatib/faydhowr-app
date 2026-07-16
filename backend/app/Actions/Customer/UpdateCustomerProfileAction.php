<?php

namespace App\Actions\Customer;

use App\Models\CustomerProfile;
use App\Models\User;

class UpdateCustomerProfileAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(User $user, array $attributes): ?CustomerProfile
    {
        $profile = $user->customerProfile()->first();

        if ($profile === null) {
            return null;
        }

        $profile->fill($attributes);

        if ($profile->isDirty()) {
            $profile->save();
        }

        return $profile->refresh();
    }
}
