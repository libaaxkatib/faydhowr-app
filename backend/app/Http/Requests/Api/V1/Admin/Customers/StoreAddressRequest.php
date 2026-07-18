<?php

namespace App\Http\Requests\Api\V1\Admin\Customers;

use App\DataTransferObjects\Customer\CreateAddressData;

class StoreAddressRequest extends CustomersFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:50'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['required', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'country' => ['nullable', 'string', 'max:2'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    public function toData(): CreateAddressData
    {
        $validated = $this->validated();

        return new CreateAddressData(
            line1: (string) $validated['address'],
            city: (string) $validated['city'],
            label: $validated['label'] ?? null,
            contactName: $validated['contact_name'] ?? null,
            phone: $validated['phone'] ?? null,
            line2: $validated['line2'] ?? null,
            stateRegion: $validated['state'] ?? null,
            district: $validated['district'] ?? null,
            postalCode: $validated['postal_code'] ?? null,
            countryCode: $validated['country'] ?? null,
            latitude: isset($validated['latitude']) ? (float) $validated['latitude'] : null,
            longitude: isset($validated['longitude']) ? (float) $validated['longitude'] : null,
            isDefault: (bool) ($validated['is_default'] ?? false),
        );
    }
}
