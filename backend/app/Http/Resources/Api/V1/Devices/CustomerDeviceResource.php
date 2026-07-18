<?php

namespace App\Http\Resources\Api\V1\Devices;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerDeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'device_id' => $this->device_id,
            'platform' => $this->platform->value,
            'push_token' => $this->push_token,
            'app_version' => $this->app_version,
            'last_seen_at' => $this->last_seen_at?->toISOString(),
            'is_active' => $this->is_active,
        ];
    }
}
