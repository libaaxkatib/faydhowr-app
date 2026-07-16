<?php

namespace App\Http\Resources\Api\V1\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'customer_number' => $this->customer_number,
            'full_name' => $this->full_name,
            'avatar_url' => $this->avatar_url,
            'preferred_language' => $this->preferred_language,
            'classification' => $this->classification,
            'notification_preferences' => $this->notification_preferences,
            'member_since' => $this->created_at?->toISOString(),
        ];
    }
}
