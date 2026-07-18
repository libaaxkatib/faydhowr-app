<?php

namespace App\Http\Requests\Api\V1\Admin\Customers;

use App\DataTransferObjects\Customer\UpdateAddressData;

class UpdateAddressRequest extends CustomersFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'nullable', 'string', 'max:50'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address' => ['sometimes', 'string', 'max:255'],
            'line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'district' => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:30'],
            'country' => ['sometimes', 'nullable', 'string', 'max:2'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    public function toData(): UpdateAddressData
    {
        $validated = $this->validated();

        return new UpdateAddressData(
            line1: $validated['address'] ?? null,
            city: $validated['city'] ?? null,
            label: array_key_exists('label', $validated) ? $validated['label'] : null,
            contactName: array_key_exists('contact_name', $validated) ? $validated['contact_name'] : null,
            phone: array_key_exists('phone', $validated) ? $validated['phone'] : null,
            line2: array_key_exists('line2', $validated) ? $validated['line2'] : null,
            stateRegion: array_key_exists('state', $validated) ? $validated['state'] : null,
            district: array_key_exists('district', $validated) ? $validated['district'] : null,
            postalCode: array_key_exists('postal_code', $validated) ? $validated['postal_code'] : null,
            countryCode: array_key_exists('country', $validated) ? $validated['country'] : null,
            latitude: array_key_exists('latitude', $validated) && $validated['latitude'] !== null
                ? (float) $validated['latitude']
                : null,
            longitude: array_key_exists('longitude', $validated) && $validated['longitude'] !== null
                ? (float) $validated['longitude']
                : null,
            isDefault: $validated['is_default'] ?? null,
            hasLatitude: array_key_exists('latitude', $validated),
            hasLongitude: array_key_exists('longitude', $validated),
        );
    }
}
