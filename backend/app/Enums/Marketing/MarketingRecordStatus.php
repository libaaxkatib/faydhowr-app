<?php

namespace App\Enums\Marketing;

/**
 * docs/HRM_MARKETING_SRS.md §13/§39: the only 4 currently-approved statuses.
 * Additional statuses require management approval — do not add more here
 * without it.
 */
enum MarketingRecordStatus: string
{
    case Pending = 'pending';
    case Quotation = 'quotation';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Quotation => 'Quotation',
            self::Done => 'Done',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
