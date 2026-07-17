<?php

namespace App\DataTransferObjects\Settings;

use App\Contracts\Settings\SettingsCategoryValuesInterface;

final readonly class CurrencySettingsData implements SettingsCategoryValuesInterface
{
    public function __construct(
        public ?string $default,
        public ?string $symbol,
        public ?int $decimalPlaces,
        public ?string $thousandSeparator,
    ) {}

    public static function fromValues(array $values): static
    {
        return new self(
            default: $values['default'] ?? null,
            symbol: $values['symbol'] ?? null,
            decimalPlaces: isset($values['decimal_places']) ? (int) $values['decimal_places'] : null,
            thousandSeparator: $values['thousand_separator'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'currency.default' => $this->default,
            'currency.symbol' => $this->symbol,
            'currency.decimal_places' => $this->decimalPlaces,
            'currency.thousand_separator' => $this->thousandSeparator,
        ];
    }
}
