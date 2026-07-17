<?php

namespace App\DataTransferObjects\Settings;

use App\Contracts\Settings\SettingsCategoryValuesInterface;

final readonly class LocalizationSettingsData implements SettingsCategoryValuesInterface
{
    public function __construct(
        public ?string $language,
        public ?string $timezone,
        public ?string $dateFormat,
        public ?string $timeFormat,
    ) {}

    public static function fromValues(array $values): static
    {
        return new self(
            language: $values['language'] ?? null,
            timezone: $values['timezone'] ?? null,
            dateFormat: $values['date_format'] ?? null,
            timeFormat: $values['time_format'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'localization.language' => $this->language,
            'localization.timezone' => $this->timezone,
            'localization.date_format' => $this->dateFormat,
            'localization.time_format' => $this->timeFormat,
        ];
    }
}
