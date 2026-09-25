<?php

namespace App\Enums;

/**
 * docs/HRM_MARKETING_SRS.md HR §28. 'Signed' is the threshold that unlocks
 * Uniform (see MarkEmployeeContractSignedAction) - 'verified' is available
 * for a future stricter workflow but is not required to progress the
 * pipeline in this phase.
 */
enum EmployeeContractStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Signed = 'signed';
    case Verified = 'verified';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Issued => 'Issued',
            self::Signed => 'Signed',
            self::Verified => 'Verified',
            self::Expired => 'Expired',
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
