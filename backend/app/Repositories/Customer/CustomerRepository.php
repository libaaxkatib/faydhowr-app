<?php

namespace App\Repositories\Customer;

use App\Contracts\Customer\Repositories\CustomerRepositoryInterface;
use App\DataTransferObjects\Customer\CustomerSearchFiltersData;
use App\Enums\Customer\CustomerStatus;
use App\Exceptions\Customer\CustomerNotFoundException;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Support\Search\CatalogSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class CustomerRepository implements CustomerRepositoryInterface
{
    public function find(int $id, bool $withTrashed = false): ?CustomerProfile
    {
        $query = CustomerProfile::query()->with('user');

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->find($id);
    }

    public function findOrFail(int $id, bool $withTrashed = false): CustomerProfile
    {
        $profile = $this->find($id, $withTrashed);

        if ($profile === null) {
            throw CustomerNotFoundException::forId($id);
        }

        return $profile;
    }

    public function paginate(CustomerSearchFiltersData $filters): LengthAwarePaginator
    {
        $query = CustomerProfile::query()->with('user');

        if ($filters->status === CustomerStatus::Deleted->value) {
            $query->onlyTrashed();
        } else {
            $query->withoutTrashed();

            if ($filters->status !== null) {
                $query->where('status', $filters->status);
            }
        }

        if ($filters->search !== null && $filters->search !== '') {
            $search = '%'.CatalogSearch::escapeLike($filters->search).'%';
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('customer_number', 'like', $search)
                    ->orWhere('full_name', 'like', $search)
                    ->orWhereHas('user', function (Builder $userQuery) use ($search): void {
                        $userQuery
                            ->where('phone', 'like', $search)
                            ->orWhere('email', 'like', $search);
                    });
            });
        }

        if ($filters->registeredFrom !== null) {
            $query->whereDate('created_at', '>=', $filters->registeredFrom);
        }

        if ($filters->registeredTo !== null) {
            $query->whereDate('created_at', '<=', $filters->registeredTo);
        }

        if ($filters->lastLoginFrom !== null) {
            $query->whereHas('user', fn (Builder $q) => $q->whereDate('last_login_at', '>=', $filters->lastLoginFrom));
        }

        if ($filters->lastLoginTo !== null) {
            $query->whereHas('user', fn (Builder $q) => $q->whereDate('last_login_at', '<=', $filters->lastLoginTo));
        }

        if ($filters->country !== null || $filters->state !== null || $filters->district !== null) {
            $query->whereHas('addresses', function (Builder $addressQuery) use ($filters): void {
                if ($filters->country !== null) {
                    $addressQuery->where('country_code', $filters->country);
                }
                if ($filters->state !== null) {
                    $addressQuery->where('state_region', $filters->state);
                }
                if ($filters->district !== null) {
                    $addressQuery->where('district', $filters->district);
                }
            });
        }

        $this->applySort($query, $filters->sort);

        return $query->paginate($filters->perPage, ['*'], 'page', $filters->page);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createProfile(User $user, array $attributes): CustomerProfile
    {
        $profile = new CustomerProfile;
        $profile->forceFill($attributes);
        $profile->user()->associate($user);
        $profile->save();

        return $profile->fresh(['user']) ?? $profile;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateProfile(CustomerProfile $profile, array $attributes): CustomerProfile
    {
        $profile->forceFill($attributes)->save();

        return $profile->refresh()->load('user');
    }

    public function softDelete(CustomerProfile $profile): void
    {
        $profile->delete();
    }

    public function restore(CustomerProfile $profile, CustomerStatus $status): CustomerProfile
    {
        $profile->restore();
        $profile->forceFill(['status' => $status])->save();

        return $profile->refresh()->load('user');
    }

    public function phoneExists(string $phone, ?int $ignoreUserId = null): bool
    {
        $query = User::query()->where('phone', $phone);

        if ($ignoreUserId !== null) {
            $query->whereKeyNot($ignoreUserId);
        }

        return $query->exists();
    }

    public function emailExists(string $email, ?int $ignoreUserId = null): bool
    {
        $query = User::query()->where('email', $email);

        if ($ignoreUserId !== null) {
            $query->whereKeyNot($ignoreUserId);
        }

        return $query->exists();
    }

    public function summaryCounts(CustomerProfile $profile): array
    {
        $paymentsQuery = $profile->payments();

        return [
            'bookings' => $profile->bookings()->count(),
            'quotations' => $profile->quotations()->count(),
            'orders' => $profile->orders()->count() + $profile->storeOrders()->count(),
            'payments' => (clone $paymentsQuery)->count(),
            'total_spent' => (float) (clone $paymentsQuery)
                ->where('status', 'paid')
                ->sum('amount'),
        ];
    }

    /**
     * @param  Builder<CustomerProfile>  $query
     */
    private function applySort(Builder $query, string $sort): void
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        match ($column) {
            'customer_number' => $query->orderBy('customer_number', $direction),
            'full_name' => $query->orderBy('full_name', $direction),
            'last_login_at' => $query
                ->leftJoin('users', 'users.id', '=', 'customer_profiles.user_id')
                ->orderBy('users.last_login_at', $direction)
                ->select('customer_profiles.*'),
            default => $query->orderBy('created_at', $direction),
        };
    }
}
