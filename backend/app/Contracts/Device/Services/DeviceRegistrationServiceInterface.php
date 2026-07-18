<?php

namespace App\Contracts\Device\Services;

use App\DataTransferObjects\Device\RegisterDeviceData;
use App\Models\CustomerDevice;
use App\Models\User;

interface DeviceRegistrationServiceInterface
{
    /**
     * @return array{device: CustomerDevice, created: bool}
     */
    public function register(User $user, RegisterDeviceData $data): array;
}
