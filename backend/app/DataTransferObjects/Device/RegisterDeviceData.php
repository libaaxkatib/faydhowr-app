<?php

namespace App\DataTransferObjects\Device;

use App\Enums\DevicePlatform;

final readonly class RegisterDeviceData
{
    public function __construct(
        public string $deviceId,
        public DevicePlatform $platform,
        public ?string $pushToken,
        public ?string $appVersion,
    ) {}
}
