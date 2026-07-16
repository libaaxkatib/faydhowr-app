<?php

namespace App\Actions\Customer;

use App\Models\CustomerAddress;
use App\Models\CustomerProfile;

class GetCustomerAddressAction
{
    public function handle(CustomerProfile $profile, int $addressId): ?CustomerAddress
    {
        return $profile->addresses()->whereKey($addressId)->first();
    }
}
