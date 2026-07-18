<?php

namespace App\Http\Controllers\Api\V1\Devices;

use App\Contracts\Device\Services\DeviceRegistrationServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Devices\RegisterDeviceRequest;
use App\Http\Resources\Api\V1\Devices\CustomerDeviceResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Throwable;

class DeviceController extends Controller
{
    public function __construct(private DeviceRegistrationServiceInterface $deviceRegistration) {}

    public function store(RegisterDeviceRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return ApiResponse::error(
                'Device registration is available to customer accounts only.',
                'FORBIDDEN',
                403,
            );
        }

        try {
            $result = $this->deviceRegistration->register($user, $request->toData());
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to register device.',
                'DEVICE_REGISTRATION_FAILED',
                500,
            );
        }

        // Idempotent upsert (API Design §4.2C): 201 on first registration, 200 thereafter.
        return ApiResponse::success(
            $result['created'] ? 'Device registered successfully.' : 'Device updated successfully.',
            new CustomerDeviceResource($result['device']),
            $result['created'] ? 201 : 200,
        );
    }
}
