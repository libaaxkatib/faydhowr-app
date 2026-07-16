<?php

namespace App\Actions\Customer;

use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use Illuminate\Database\Eloquent\Collection;

class ListCustomerAddressesAction
{
    /**
     * @return Collection<int, CustomerAddress>
     */
    public function handle(CustomerProfile $profile): Collection
    {
        return $profile
            ->addresses()
            ->orderByDesc('is_default')
            ->latest()
            ->get();
    }
}
