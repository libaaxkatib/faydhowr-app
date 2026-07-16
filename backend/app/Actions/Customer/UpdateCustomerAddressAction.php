<?php

namespace App\Actions\Customer;

use App\Models\CustomerAddress;
use App\Models\CustomerProfile;

class UpdateCustomerAddressAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(
        CustomerProfile $profile,
        int $addressId,
        array $attributes,
    ): ?CustomerAddress {
        $address = $profile->addresses()->whereKey($addressId)->first();

        if ($address === null) {
            return null;
        }

        $address->fill($attributes);

        if ($address->isDirty()) {
            $address->save();
        }

        return $address->refresh();
    }
}
