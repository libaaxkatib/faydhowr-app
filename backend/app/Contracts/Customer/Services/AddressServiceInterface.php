<?php

namespace App\Contracts\Customer\Services;

use App\DataTransferObjects\Customer\CreateAddressData;
use App\DataTransferObjects\Customer\UpdateAddressData;
use App\Models\Admin;
use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use Illuminate\Support\Collection;

interface AddressServiceInterface
{
    /**
     * @return Collection<int, CustomerAddress>
     */
    public function list(CustomerProfile $profile): Collection;

    public function create(CustomerProfile $profile, CreateAddressData $data, Admin $admin): CustomerAddress;

    public function update(CustomerProfile $profile, CustomerAddress $address, UpdateAddressData $data, Admin $admin): CustomerAddress;

    public function setDefault(CustomerProfile $profile, CustomerAddress $address, Admin $admin): CustomerAddress;

    public function deactivate(CustomerProfile $profile, CustomerAddress $address, Admin $admin): CustomerAddress;
}
