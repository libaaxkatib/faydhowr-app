<?php

namespace App\Http\Resources\Api\V1\Notification;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationPreferenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{notification_type: string, in_app: bool, email: bool, sms: bool} $preference */
        $preference = $this->resource;

        return [
            'notification_type' => $preference['notification_type'],
            'in_app' => $preference['in_app'],
            'email' => $preference['email'],
            'sms' => $preference['sms'],
        ];
    }
}
