<?php

namespace App\Services\Customer;

use App\Contracts\Customer\Repositories\CustomerAddressRepositoryInterface;
use App\Contracts\Customer\Services\AddressServiceInterface;
use App\Contracts\Customer\Services\CustomerActivityServiceInterface;
use App\DataTransferObjects\Customer\CreateAddressData;
use App\DataTransferObjects\Customer\UpdateAddressData;
use App\Enums\AuditAction;
use App\Enums\Customer\ActivityType;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AddressService implements AddressServiceInterface
{
    public function __construct(
        private CustomerAddressRepositoryInterface $addresses,
        private CustomerActivityServiceInterface $activities,
    ) {}

    public function list(CustomerProfile $profile): Collection
    {
        return $this->addresses->listForProfile($profile);
    }

    public function create(CustomerProfile $profile, CreateAddressData $data, Admin $admin): CustomerAddress
    {
        $address = DB::transaction(function () use ($profile, $data): CustomerAddress {
            $attributes = $data->toAttributes();
            $attributes['is_active'] = true;

            if ($attributes['is_default'] || $profile->addresses()->where('is_active', true)->count() === 0) {
                $this->addresses->clearDefaults($profile);
                $attributes['is_default'] = true;
            }

            return $this->addresses->create($profile, $attributes);
        });

        $this->activities->record($profile, ActivityType::AddressAdded, 'Address added', $address);

        event(AuditEvent::record(
            AuditAction::Create,
            $admin,
            'Customer address created.',
            CustomerAddress::class,
            $address->id,
            ['customer_profile_id' => $profile->id],
        ));

        return $address;
    }

    public function update(CustomerProfile $profile, CustomerAddress $address, UpdateAddressData $data, Admin $admin): CustomerAddress
    {
        $this->assertOwns($profile, $address);

        $updated = DB::transaction(function () use ($profile, $address, $data): CustomerAddress {
            $attributes = $data->toAttributes();

            if (($attributes['is_default'] ?? false) === true) {
                $this->addresses->clearDefaults($profile, $address->id);
            }

            return $this->addresses->update($address, $attributes);
        });

        $this->activities->record($profile, ActivityType::AddressUpdated, 'Address updated', $updated);

        event(AuditEvent::record(
            AuditAction::Update,
            $admin,
            'Customer address updated.',
            CustomerAddress::class,
            $updated->id,
            ['customer_profile_id' => $profile->id],
        ));

        return $updated;
    }

    public function setDefault(CustomerProfile $profile, CustomerAddress $address, Admin $admin): CustomerAddress
    {
        $this->assertOwns($profile, $address);

        $updated = DB::transaction(fn (): CustomerAddress => $this->addresses->setDefault($address));

        $this->activities->record($profile, ActivityType::AddressUpdated, 'Default address updated', $updated);

        event(AuditEvent::record(
            AuditAction::Update,
            $admin,
            'Customer default address set.',
            CustomerAddress::class,
            $updated->id,
            ['customer_profile_id' => $profile->id],
        ));

        return $updated;
    }

    public function deactivate(CustomerProfile $profile, CustomerAddress $address, Admin $admin): CustomerAddress
    {
        $this->assertOwns($profile, $address);

        $updated = $this->addresses->deactivate($address);

        event(AuditEvent::record(
            AuditAction::Update,
            $admin,
            'Customer address deactivated.',
            CustomerAddress::class,
            $updated->id,
            ['customer_profile_id' => $profile->id],
        ));

        return $updated;
    }

    private function assertOwns(CustomerProfile $profile, CustomerAddress $address): void
    {
        if ((int) $address->customer_profile_id !== (int) $profile->id) {
            abort(404);
        }
    }
}
