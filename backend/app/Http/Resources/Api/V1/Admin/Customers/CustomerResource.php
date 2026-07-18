<?php

namespace App\Http\Resources\Api\V1\Admin\Customers;

use App\Models\CustomerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerProfile
 */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_number' => $this->customer_number,
            'full_name' => $this->full_name,
            'phone' => $this->user?->phone,
            'email' => $this->user?->email,
            'gender' => $this->gender?->value,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'avatar_url' => $this->avatar_url,
            'preferred_language' => $this->preferred_language,
            'status' => $this->displayStatus()->value,
            'classification' => $this->classification,
            'tags' => $this->tags ?? [],
            'registered_at' => $this->created_at?->toISOString(),
            'last_login_at' => $this->user?->last_login_at?->toISOString(),
            'summary' => $this->when(isset($this->summary_counts), $this->summary_counts),
        ];
    }
}
