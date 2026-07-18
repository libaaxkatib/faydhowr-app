<?php

namespace App\Contracts\Customer\Services;

use App\Enums\Customer\ActivityType;
use App\Models\CustomerActivityLog;
use App\Models\CustomerProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

interface CustomerActivityServiceInterface
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function record(
        CustomerProfile $profile,
        ActivityType $type,
        ?string $description = null,
        ?Model $subject = null,
        ?array $metadata = null,
    ): CustomerActivityLog;

    /**
     * @return LengthAwarePaginator<int, CustomerActivityLog>
     */
    public function timeline(CustomerProfile $profile, int $perPage = 25): LengthAwarePaginator;

    /**
     * @return LengthAwarePaginator<int, CustomerActivityLog>
     */
    public function activityLogs(
        CustomerProfile $profile,
        ?string $eventType = null,
        ?string $from = null,
        ?string $to = null,
        int $perPage = 25,
    ): LengthAwarePaginator;
}
