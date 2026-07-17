<?php

namespace App\DataTransferObjects\Settings;

use App\Contracts\Settings\SettingsCategoryValuesInterface;

final readonly class TaxSettingsData implements SettingsCategoryValuesInterface
{
    public function __construct(
        public ?bool $default,
        public int|float|null $rate,
        public ?string $mode,
    ) {}

    public static function fromValues(array $values): static
    {
        return new self(
            default: isset($values['default']) ? (bool) $values['default'] : null,
            rate: $values['rate'] ?? null,
            mode: $values['mode'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'tax.default' => $this->default,
            'tax.rate' => $this->rate,
            'tax.mode' => $this->mode,
        ];
    }
}
