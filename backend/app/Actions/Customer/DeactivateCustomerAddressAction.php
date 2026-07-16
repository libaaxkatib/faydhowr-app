<?php

namespace App\Actions\Customer;

use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use DomainException;
use Illuminate\Support\Facades\DB;

class DeactivateCustomerAddressAction
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
                return $address;
            }

            $activeAddressCount = $profile->addresses()->where('is_active', true)->count();

            if ($activeAddressCount <= 1) {
                throw new DomainException('The last active address cannot be made inactive.');
            }

            if ($address->is_default) {
                throw new DomainException(
                    'Set another active address as default before making this address inactive.',
                );
            }

            $address->is_active = false;
            $address->save();

            return $address->refresh();
        });
    }
}
