<?php

namespace App\Services\Customer;

use App\Contracts\Customer\Repositories\CustomerRepositoryInterface;
use App\Contracts\Customer\Services\CustomerActivityServiceInterface;
use App\Contracts\Customer\Services\CustomerServiceInterface;
use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\DataTransferObjects\Customer\CreateCustomerData;
use App\DataTransferObjects\Customer\CustomerSearchFiltersData;
use App\DataTransferObjects\Customer\RestoreCustomerData;
use App\DataTransferObjects\Customer\UpdateCustomerData;
use App\DataTransferObjects\Customer\UpdateCustomerStatusData;
use App\Enums\AuditAction;
use App\Enums\Customer\ActivityType;
use App\Enums\Customer\CustomerStatus;
use App\Enums\UserStatus;
use App\Events\Audit\AuditEvent;
use App\Exceptions\Customer\CustomerAlreadyDeletedException;
use App\Exceptions\Customer\CustomerEmailTakenException;
use App\Exceptions\Customer\CustomerInvalidStatusException;
use App\Exceptions\Customer\CustomerNotDeletedException;
use App\Exceptions\Customer\CustomerOperationDeniedException;
use App\Exceptions\Customer\CustomerPhoneTakenException;
use App\Models\Admin;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Support\Customer\CustomerCodeGenerator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CustomerService implements CustomerServiceInterface
{
    public function __construct(
        private CustomerRepositoryInterface $customers,
        private CustomerCodeGenerator $codes,
        private CustomerActivityServiceInterface $activities,
        private DashboardCacheInvalidatorInterface $dashboardCache,
    ) {}

    public function paginate(CustomerSearchFiltersData $filters): LengthAwarePaginator
    {
        return $this->customers->paginate($filters);
    }

    public function find(int $id, bool $withTrashed = false): CustomerProfile
    {
        return $this->customers->findOrFail($id, $withTrashed);
    }

    public function show(int $id, bool $withTrashed = false): array
    {
        $profile = $this->customers->findOrFail($id, $withTrashed);

        return [
            'profile' => $profile,
            'summary' => $this->customers->summaryCounts($profile),
        ];
    }

    public function create(CreateCustomerData $data, Admin $admin): CustomerProfile
    {
        if ($this->customers->phoneExists($data->phone)) {
            throw CustomerPhoneTakenException::make();
        }

        if ($data->email !== null && $this->customers->emailExists(Str::lower($data->email))) {
            throw CustomerEmailTakenException::make();
        }

        $profile = DB::transaction(function () use ($data): CustomerProfile {
            $user = User::query()->create([
                'name' => $data->fullName,
                'email' => $data->email !== null ? Str::lower($data->email) : $this->placeholderEmail($data->phone),
                'phone' => $data->phone,
                'password' => Hash::make($data->password),
                'status' => UserStatus::Active,
            ]);

            $profile = $this->customers->createProfile($user, [
                'customer_number' => $this->codes->next(),
                'full_name' => $data->fullName,
                'avatar_url' => $data->avatarUrl,
                'gender' => $data->gender,
                'date_of_birth' => $data->dateOfBirth,
                'preferred_language' => $data->preferredLanguage,
                'status' => CustomerStatus::Active,
                'tags' => $data->tags,
                'classification' => 'lead',
            ]);

            $this->activities->record($profile, ActivityType::Registration, 'Account registered');

            return $profile;
        });

        event(AuditEvent::record(
            AuditAction::Create,
            $admin,
            'Customer account created.',
            CustomerProfile::class,
            $profile->id,
            ['customer_number' => $profile->customer_number],
        ));

        $this->dashboardCache->invalidate();

        return $profile;
    }

    public function update(CustomerProfile $profile, UpdateCustomerData $data, Admin $admin): CustomerProfile
    {
        $user = $profile->user;
        $old = [
            'full_name' => $profile->full_name,
            'phone' => $user->phone,
            'email' => $user->email,
        ];

        if ($data->phone !== null && $this->customers->phoneExists($data->phone, $user->id)) {
            throw CustomerPhoneTakenException::make();
        }

        if ($data->email !== null && $this->customers->emailExists(Str::lower($data->email), $user->id)) {
            throw CustomerEmailTakenException::make();
        }

        $updated = DB::transaction(function () use ($profile, $user, $data): CustomerProfile {
            $userAttributes = [];

            if ($data->phone !== null) {
                $userAttributes['phone'] = $data->phone;
            }

            if ($data->clearEmail) {
                $userAttributes['email'] = $this->placeholderEmail($data->phone ?? $user->phone ?? (string) $user->id);
            } elseif ($data->email !== null) {
                $userAttributes['email'] = Str::lower($data->email);
            }

            if ($data->fullName !== null) {
                $userAttributes['name'] = $data->fullName;
            }

            if ($userAttributes !== []) {
                $user->forceFill($userAttributes)->save();
            }

            $profileAttributes = [];

            if ($data->fullName !== null) {
                $profileAttributes['full_name'] = $data->fullName;
            }
            if ($data->preferredLanguage !== null) {
                $profileAttributes['preferred_language'] = $data->preferredLanguage;
            }
            if ($data->clearGender) {
                $profileAttributes['gender'] = null;
            } elseif ($data->gender !== null) {
                $profileAttributes['gender'] = $data->gender;
            }
            if ($data->clearDateOfBirth) {
                $profileAttributes['date_of_birth'] = null;
            } elseif ($data->dateOfBirth !== null) {
                $profileAttributes['date_of_birth'] = $data->dateOfBirth;
            }
            if ($data->clearTags) {
                $profileAttributes['tags'] = null;
            } elseif ($data->tags !== null) {
                $profileAttributes['tags'] = $data->tags;
            }
            if ($data->clearAvatarUrl) {
                $profileAttributes['avatar_url'] = null;
            } elseif ($data->avatarUrl !== null) {
                $profileAttributes['avatar_url'] = $data->avatarUrl;
            }

            if ($profileAttributes !== []) {
                return $this->customers->updateProfile($profile, $profileAttributes);
            }

            return $profile->refresh()->load('user');
        });

        $this->activities->record($updated, ActivityType::ProfileUpdate, 'Profile updated');

        event(AuditEvent::record(
            AuditAction::Update,
            $admin,
            'Customer profile updated.',
            CustomerProfile::class,
            $updated->id,
            ['old' => $old, 'new' => [
                'full_name' => $updated->full_name,
                'phone' => $updated->user->phone,
                'email' => $updated->user->email,
            ]],
        ));

        return $updated;
    }

    public function updateStatus(CustomerProfile $profile, UpdateCustomerStatusData $data, Admin $admin): CustomerProfile
    {
        if ($profile->trashed()) {
            throw CustomerAlreadyDeletedException::make();
        }

        if (! in_array($data->status, [CustomerStatus::Active, CustomerStatus::Inactive, CustomerStatus::Blocked], true)) {
            throw CustomerInvalidStatusException::forValue($data->status->value);
        }

        $oldStatus = $profile->status?->value;

        // Intentionally updates ONLY customer_profiles.status — never users.status.
        $updated = $this->customers->updateProfile($profile, [
            'status' => $data->status,
        ]);

        event(AuditEvent::record(
            AuditAction::Update,
            $admin,
            'Customer business status updated.',
            CustomerProfile::class,
            $updated->id,
            [
                'field' => 'customer_profiles.status',
                'old_value' => $oldStatus,
                'new_value' => $data->status->value,
            ],
        ));

        return $updated;
    }

    public function delete(CustomerProfile $profile, Admin $admin): void
    {
        if ($profile->trashed()) {
            throw CustomerAlreadyDeletedException::make();
        }

        $this->customers->softDelete($profile);

        event(AuditEvent::record(
            AuditAction::Delete,
            $admin,
            'Customer soft-deleted.',
            CustomerProfile::class,
            $profile->id,
            ['customer_number' => $profile->customer_number],
        ));

        $this->dashboardCache->invalidate();
    }

    public function restore(CustomerProfile $profile, RestoreCustomerData $data, Admin $admin): CustomerProfile
    {
        if (! $profile->trashed()) {
            throw CustomerNotDeletedException::make();
        }

        if (! in_array($data->status->value, CustomerStatus::restoreValues(), true)) {
            throw CustomerInvalidStatusException::restoreTarget($data->status->value);
        }

        // Restores customer_profiles.status only — never users.status.
        $restored = $this->customers->restore($profile, $data->status);

        event(AuditEvent::record(
            AuditAction::Update,
            $admin,
            'Customer restored.',
            CustomerProfile::class,
            $restored->id,
            [
                'field' => 'customer_profiles.status',
                'new_value' => $data->status->value,
            ],
        ));

        $this->dashboardCache->invalidate();

        return $restored;
    }

    public function assertCanTransact(CustomerProfile $profile): void
    {
        if (! $profile->canUseCustomerServices()) {
            throw CustomerOperationDeniedException::inactiveOrBlocked();
        }
    }

    private function placeholderEmail(string $seed): string
    {
        return 'customer+'.Str::lower(preg_replace('/\D+/', '', $seed) ?: Str::random(8)).'@users.local';
    }
}
