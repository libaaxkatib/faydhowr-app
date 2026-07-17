<?php

namespace App\Actions\Customer;

use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Models\CustomerProfile;
use App\Models\User;

class UpdateCustomerProfileAction
{
    public function __construct(private DashboardCacheInvalidatorInterface $dashboardCache) {}

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
            $this->dashboardCache->invalidate();
        }

        return $profile->refresh();
    }
}
