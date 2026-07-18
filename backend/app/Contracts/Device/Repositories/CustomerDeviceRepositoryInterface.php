<?php

namespace App\Contracts\Device\Repositories;

use App\DataTransferObjects\Device\RegisterDeviceData;
use App\Models\CustomerDevice;
use App\Models\User;

interface CustomerDeviceRepositoryInterface
{
    public function findForUser(int $userId, string $deviceId): ?CustomerDevice;

    /**
     * Idempotent upsert keyed by (user_id, device_id). Re-registration updates
     * platform, push_token, app_version, and last_seen_at, and reactivates the row.
     */
    public function upsert(User $user, RegisterDeviceData $data): CustomerDevice;
}
