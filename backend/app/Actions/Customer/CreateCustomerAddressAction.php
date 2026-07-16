<?php

namespace App\Actions\Customer;

use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use Illuminate\Support\Facades\DB;

class CreateCustomerAddressAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(CustomerProfile $profile, array $attributes): CustomerAddress
    {
        return DB::transaction(function () use ($profile, $attributes): CustomerAddress {
            $profile = CustomerProfile::query()
                ->whereKey($profile)
                ->lockForUpdate()
                ->firstOrFail();

            $attributes['is_default'] = ! $profile
                ->addresses()
                ->where('is_active', true)
                ->exists();

            if ($attributes['is_default']) {
                $profile->addresses()->where('is_default', true)->update(['is_default' => false]);
            }

            return $profile->addresses()->create($attributes);
        });
    }
}
