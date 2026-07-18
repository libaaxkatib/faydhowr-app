<?php

namespace App\Repositories\Device;

use App\Contracts\Device\Repositories\CustomerDeviceRepositoryInterface;
use App\DataTransferObjects\Device\RegisterDeviceData;
use App\Models\CustomerDevice;
use App\Models\User;

class CustomerDeviceRepository implements CustomerDeviceRepositoryInterface
{
    public function findForUser(int $userId, string $deviceId): ?CustomerDevice
    {
        return CustomerDevice::query()
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->first();
    }

    public function upsert(User $user, RegisterDeviceData $data): CustomerDevice
    {
        return CustomerDevice::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'device_id' => $data->deviceId,
            ],
            [
                'platform' => $data->platform,
                'push_token' => $data->pushToken,
                'app_version' => $data->appVersion,
                'last_seen_at' => now(),
                'is_active' => true,
            ],
        );
    }
}
