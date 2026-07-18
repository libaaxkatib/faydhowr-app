<?php

namespace App\Repositories\Customer;

use App\Contracts\Customer\Repositories\CustomerActivityRepositoryInterface;
use App\Enums\Customer\ActivityType;
use App\Models\CustomerActivityLog;
use App\Models\CustomerProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

class CustomerActivityRepository implements CustomerActivityRepositoryInterface
{
    public function record(
        CustomerProfile $profile,
        ActivityType $type,
        ?string $description = null,
        ?Model $subject = null,
        ?array $metadata = null,
    ): CustomerActivityLog {
        return CustomerActivityLog::query()->create([
            'customer_profile_id' => $profile->id,
            'event_type' => $type,
            'description' => $description,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    public function timeline(CustomerProfile $profile, int $perPage = 25): LengthAwarePaginator
    {
        return $profile->activityLogs()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function filtered(
        CustomerProfile $profile,
        ?string $eventType = null,
        ?string $from = null,
        ?string $to = null,
        int $perPage = 25,
    ): LengthAwarePaginator {
        $query = $profile->activityLogs()->orderByDesc('created_at')->orderByDesc('id');

        if ($eventType !== null) {
            $query->where('event_type', $eventType);
        }

        if ($from !== null) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate('created_at', '<=', $to);
        }

        return $query->paginate($perPage);
    }
}
