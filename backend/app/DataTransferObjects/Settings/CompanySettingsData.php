<?php

namespace App\DataTransferObjects\Settings;

use App\Contracts\Settings\SettingsCategoryValuesInterface;

final readonly class CompanySettingsData implements SettingsCategoryValuesInterface
{
    public function __construct(
        public ?string $name,
        public ?string $logo,
        public ?string $email,
        public ?string $phone,
        public ?string $website,
        public ?string $address,
        public ?string $taxId,
        public ?string $businessHoursOpen,
        public ?string $businessHoursClose,
        public ?string $facebook,
        public ?string $instagram,
        public ?string $whatsapp,
    ) {}

    public static function fromValues(array $values): static
    {
        return new self(
            name: $values['name'] ?? null,
            logo: $values['logo'] ?? null,
            email: $values['email'] ?? null,
            phone: $values['phone'] ?? null,
            website: $values['website'] ?? null,
            address: $values['address'] ?? null,
            taxId: $values['tax_id'] ?? null,
            businessHoursOpen: $values['business_hours_open'] ?? null,
            businessHoursClose: $values['business_hours_close'] ?? null,
            facebook: $values['facebook'] ?? null,
            instagram: $values['instagram'] ?? null,
            whatsapp: $values['whatsapp'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'company.name' => $this->name,
            'company.logo' => $this->logo,
            'company.email' => $this->email,
            'company.phone' => $this->phone,
            'company.website' => $this->website,
            'company.address' => $this->address,
            'company.tax_id' => $this->taxId,
            'company.business_hours_open' => $this->businessHoursOpen,
            'company.business_hours_close' => $this->businessHoursClose,
            'company.facebook' => $this->facebook,
            'company.instagram' => $this->instagram,
            'company.whatsapp' => $this->whatsapp,
        ];
    }
}
