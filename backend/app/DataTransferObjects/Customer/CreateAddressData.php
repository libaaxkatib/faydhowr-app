<?php

namespace App\DataTransferObjects\Customer;

readonly class CreateAddressData
{
    public function __construct(
        public string $line1,
        public string $city,
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
        public bool $isDefault = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'label' => $this->label,
            'contact_name' => $this->contactName,
            'phone' => $this->phone,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'state_region' => $this->stateRegion,
            'district' => $this->district,
            'postal_code' => $this->postalCode,
            'country_code' => $this->countryCode,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'is_default' => $this->isDefault,
        ];
    }
}
