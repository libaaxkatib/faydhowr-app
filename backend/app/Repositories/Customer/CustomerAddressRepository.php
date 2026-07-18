<?php

namespace App\Repositories\Customer;

use App\Contracts\Customer\Repositories\CustomerAddressRepositoryInterface;
use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use Illuminate\Support\Collection;

class CustomerAddressRepository implements CustomerAddressRepositoryInterface
{
    public function listForProfile(CustomerProfile $profile): Collection
    {
        return $profile->addresses()->orderByDesc('is_default')->orderByDesc('id')->get();
    }

    public function findForProfile(CustomerProfile $profile, int $addressId): ?CustomerAddress
    {
        return $profile->addresses()->whereKey($addressId)->first();
    }

    public function create(CustomerProfile $profile, array $attributes): CustomerAddress
    {
        /** @var CustomerAddress $address */
        $address = $profile->addresses()->create($attributes);

        return $address->refresh();
    }

    public function update(CustomerAddress $address, array $attributes): CustomerAddress
    {
        $address->fill($attributes)->save();

        return $address->refresh();
    }

    public function clearDefaults(CustomerProfile $profile, ?int $exceptId = null): void
    {
        $query = $profile->addresses()->where('is_default', true);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        $query->update(['is_default' => false]);
    }

    public function setDefault(CustomerAddress $address): CustomerAddress
    {
        $this->clearDefaults($address->customerProfile, $address->id);
        $address->forceFill(['is_default' => true, 'is_active' => true])->save();

        return $address->refresh();
    }

    public function deactivate(CustomerAddress $address): CustomerAddress
    {
        $address->forceFill([
            'is_active' => false,
            'is_default' => false,
        ])->save();

        return $address->refresh();
    }
}
