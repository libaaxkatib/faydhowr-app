<?php

namespace App\Actions\Customer;

use App\Models\CustomerAddress;
use App\Models\CustomerProfile;

class ReactivateCustomerAddressAction
{
    public function handle(CustomerProfile $profile, int $addressId): ?CustomerAddress
    {
        $address = $profile->addresses()->whereKey($addressId)->first();

        if ($address === null) {
            return null;
        }

        $address->is_active = true;
        $address->save();

        return $address->refresh();
    }
}
