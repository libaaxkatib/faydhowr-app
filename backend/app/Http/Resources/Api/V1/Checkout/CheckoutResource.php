<?php

namespace App\Http\Resources\Api\V1\Checkout;

use App\Models\CustomerAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckoutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{items: list<array<string, mixed>>, totals: array<string, mixed>, address: CustomerAddress} $payload */
        $payload = $this->resource;

        $address = $payload['address'];

        return [
            'items' => $payload['items'],
            'totals' => $payload['totals'],
            'address' => [
                'label' => $address->label,
                'contact_name' => $address->contact_name,
                'phone' => $address->phone,
                'line1' => $address->line1,
                'line2' => $address->line2,
                'city' => $address->city,
                'state_region' => $address->state_region,
                'postal_code' => $address->postal_code,
                'country_code' => $address->country_code,
            ],
        ];
    }
}
