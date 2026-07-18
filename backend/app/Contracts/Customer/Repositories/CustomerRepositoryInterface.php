<?php

namespace App\Contracts\Customer\Repositories;

use App\DataTransferObjects\Customer\CustomerSearchFiltersData;
use App\Enums\Customer\CustomerStatus;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CustomerRepositoryInterface
{
    public function find(int $id, bool $withTrashed = false): ?CustomerProfile;

    public function findOrFail(int $id, bool $withTrashed = false): CustomerProfile;

    /**
     * @return LengthAwarePaginator<int, CustomerProfile>
     */
    public function paginate(CustomerSearchFiltersData $filters): LengthAwarePaginator;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createProfile(User $user, array $attributes): CustomerProfile;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateProfile(CustomerProfile $profile, array $attributes): CustomerProfile;

    public function softDelete(CustomerProfile $profile): void;

    public function restore(CustomerProfile $profile, CustomerStatus $status): CustomerProfile;

    public function phoneExists(string $phone, ?int $ignoreUserId = null): bool;

    public function emailExists(string $email, ?int $ignoreUserId = null): bool;

    /**
     * @return array{bookings: int, quotations: int, orders: int, payments: int, total_spent: float}
     */
    public function summaryCounts(CustomerProfile $profile): array;
}
