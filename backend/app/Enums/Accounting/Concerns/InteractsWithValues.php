<?php

namespace App\Enums\Accounting\Concerns;

use InvalidArgumentException;

/**
 * Shared helpers for the string-backed Accounting enums.
 */
trait InteractsWithValues
{
    abstract public function label(): string;

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<string, string> Map of backing value to label.
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $case) {
            $labels[$case->value] = $case->label();
        }

        return $labels;
    }

    public static function fromValue(string $value): self
    {
        return self::tryFrom($value) ?? throw new InvalidArgumentException(sprintf(
            '"%s" is not a valid %s value. Valid values: %s.',
            $value,
            static::class,
            implode(', ', self::values()),
        ));
    }
}
