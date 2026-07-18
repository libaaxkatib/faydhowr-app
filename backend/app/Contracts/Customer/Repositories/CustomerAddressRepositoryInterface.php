<?php

namespace App\Contracts\Customer\Repositories;

use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use Illuminate\Support\Collection;

interface CustomerAddressRepositoryInterface
{
    /**
     * @return Collection<int, CustomerAddress>
     */
    public function listForProfile(CustomerProfile $profile): Collection;

    public function findForProfile(CustomerProfile $profile, int $addressId): ?CustomerAddress;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(CustomerProfile $profile, array $attributes): CustomerAddress;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(CustomerAddress $address, array $attributes): CustomerAddress;

    public function clearDefaults(CustomerProfile $profile, ?int $exceptId = null): void;

    public function setDefault(CustomerAddress $address): CustomerAddress;

    public function deactivate(CustomerAddress $address): CustomerAddress;
}
