<?php

namespace App\Services\Device;

use App\Contracts\Device\Repositories\CustomerDeviceRepositoryInterface;
use App\Contracts\Device\Services\DeviceRegistrationServiceInterface;
use App\DataTransferObjects\Device\RegisterDeviceData;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeviceRegistrationService implements DeviceRegistrationServiceInterface
{
    public function __construct(private CustomerDeviceRepositoryInterface $devices) {}

    public function register(User $user, RegisterDeviceData $data): array
    {
        return DB::transaction(function () use ($user, $data): array {
            $existing = $this->devices->findForUser($user->id, $data->deviceId);

            $device = $this->devices->upsert($user, $data);

            return [
                'device' => $device,
                'created' => $existing === null,
            ];
        });
    }
}
