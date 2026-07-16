<?php

namespace App\Actions\Customer;

use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use Illuminate\Support\Facades\DB;

class ReactivateCustomerAddressAction
{
    public function handle(CustomerProfile $profile, int $addressId): ?CustomerAddress
    {
        return DB::transaction(function () use ($profile, $addressId): ?CustomerAddress {
            $profile = CustomerProfile::query()
                ->whereKey($profile)
                ->lockForUpdate()
                ->firstOrFail();

            $address = $profile
                ->addresses()
                ->whereKey($addressId)
                ->lockForUpdate()
                ->first();

            if ($address === null) {
                return null;
            }

            $address->is_active = true;
            $address->is_default = false;
            $address->save();

            return $address->refresh();
        });
    }
}
