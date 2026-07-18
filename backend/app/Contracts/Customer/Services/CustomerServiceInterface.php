<?php

namespace App\Contracts\Customer\Services;

use App\DataTransferObjects\Customer\CreateCustomerData;
use App\DataTransferObjects\Customer\CustomerSearchFiltersData;
use App\DataTransferObjects\Customer\RestoreCustomerData;
use App\DataTransferObjects\Customer\UpdateCustomerData;
use App\DataTransferObjects\Customer\UpdateCustomerStatusData;
use App\Models\Admin;
use App\Models\CustomerProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CustomerServiceInterface
{
    /**
     * @return LengthAwarePaginator<int, CustomerProfile>
     */
    public function paginate(CustomerSearchFiltersData $filters): LengthAwarePaginator;

    public function find(int $id, bool $withTrashed = false): CustomerProfile;

    /**
     * @return array{profile: CustomerProfile, summary: array{bookings: int, quotations: int, orders: int, payments: int, total_spent: float}}
     */
    public function show(int $id, bool $withTrashed = false): array;

    public function create(CreateCustomerData $data, Admin $admin): CustomerProfile;

    public function update(CustomerProfile $profile, UpdateCustomerData $data, Admin $admin): CustomerProfile;

    public function updateStatus(CustomerProfile $profile, UpdateCustomerStatusData $data, Admin $admin): CustomerProfile;

    public function delete(CustomerProfile $profile, Admin $admin): void;

    public function restore(CustomerProfile $profile, RestoreCustomerData $data, Admin $admin): CustomerProfile;

    public function assertCanTransact(CustomerProfile $profile): void;
}
