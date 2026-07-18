<?php

namespace App\Http\Resources\Api\V1\Admin\Customers;

use App\Models\CustomerAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerAddress
 */
class AddressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'contact_name' => $this->contact_name,
            'phone' => $this->phone,
            'address' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'state' => $this->state_region,
            'district' => $this->district,
            'postal_code' => $this->postal_code,
            'country' => $this->country_code,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
