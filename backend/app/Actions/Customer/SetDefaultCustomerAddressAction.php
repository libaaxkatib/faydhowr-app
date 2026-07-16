<?php

namespace App\Actions\Customer;

use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use DomainException;
use Illuminate\Support\Facades\DB;

class SetDefaultCustomerAddressAction
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

            if (! $address->is_active) {
                throw new DomainException('An inactive address cannot be set as default.');
            }

            $profile->addresses()->where('is_default', true)->update(['is_default' => false]);

            $address->is_default = true;
            $address->save();

            return $address->refresh();
        });
    }
}
