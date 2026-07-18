<?php

namespace App\DataTransferObjects\Customer;

readonly class UpdateAddressData
{
    public function __construct(
        public ?string $line1 = null,
        public ?string $city = null,
        public ?string $label = null,
        public ?string $contactName = null,
        public ?string $phone = null,
        public ?string $line2 = null,
        public ?string $stateRegion = null,
        public ?string $district = null,
        public ?string $postalCode = null,
        public ?string $countryCode = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?bool $isDefault = null,
        public bool $hasLatitude = false,
        public bool $hasLongitude = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        $attributes = [];

        foreach ([
            'line1' => $this->line1,
            'city' => $this->city,
            'label' => $this->label,
            'contact_name' => $this->contactName,
            'phone' => $this->phone,
            'line2' => $this->line2,
            'state_region' => $this->stateRegion,
            'district' => $this->district,
            'postal_code' => $this->postalCode,
            'country_code' => $this->countryCode,
            'is_default' => $this->isDefault,
        ] as $key => $value) {
            if ($value !== null) {
                $attributes[$key] = $value;
            }
        }

        if ($this->hasLatitude) {
            $attributes['latitude'] = $this->latitude;
        }

        if ($this->hasLongitude) {
            $attributes['longitude'] = $this->longitude;
        }

        return $attributes;
    }
}
