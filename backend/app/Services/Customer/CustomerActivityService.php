<?php

namespace App\Services\Customer;

use App\Contracts\Customer\Repositories\CustomerActivityRepositoryInterface;
use App\Contracts\Customer\Services\CustomerActivityServiceInterface;
use App\Enums\Customer\ActivityType;
use App\Models\CustomerActivityLog;
use App\Models\CustomerProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

class CustomerActivityService implements CustomerActivityServiceInterface
{
    public function __construct(private CustomerActivityRepositoryInterface $activities) {}

    public function record(
        CustomerProfile $profile,
        ActivityType $type,
        ?string $description = null,
        ?Model $subject = null,
        ?array $metadata = null,
    ): CustomerActivityLog {
        return $this->activities->record(
            $profile,
            $type,
            $description ?? $type->label(),
            $subject,
            $metadata,
        );
    }

    public function timeline(CustomerProfile $profile, int $perPage = 25): LengthAwarePaginator
    {
        return $this->activities->timeline($profile, $perPage);
    }

    public function activityLogs(
        CustomerProfile $profile,
        ?string $eventType = null,
        ?string $from = null,
        ?string $to = null,
        int $perPage = 25,
    ): LengthAwarePaginator {
        return $this->activities->filtered($profile, $eventType, $from, $to, $perPage);
    }
}
