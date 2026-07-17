<?php

namespace App\Exceptions\Reports;

use DomainException;

class InvalidReportFilterException extends DomainException
{
    public static function unsupportedFilter(string $key): self
    {
        return new self("Report filter [{$key}] is not supported.");
    }

    public static function invalidDate(string $key, mixed $value): self
    {
        $formatted = is_scalar($value) ? (string) $value : gettype($value);

        return new self("Report filter [{$key}] must be a valid date, [{$formatted}] given.");
    }

    public static function invalidDateRange(): self
    {
        return new self('Report filter [date_from] must be before or equal to [date_to].');
    }

    public static function invalidId(string $key, mixed $value): self
    {
        $formatted = is_scalar($value) ? (string) $value : gettype($value);

        return new self("Report filter [{$key}] must be a positive integer, [{$formatted}] given.");
    }

    public static function invalidStructure(string $key): self
    {
        return new self("Report filter [{$key}] has an invalid structure.");
    }
}
