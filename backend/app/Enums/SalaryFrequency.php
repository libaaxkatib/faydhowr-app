<?php

namespace App\Enums;

/**
 * Minimal value set inferred from the "$180/month" example in the work-
 * assignment requirement — not stated as a closed list anywhere. Additive
 * to widen later (e.g. biweekly) with one more CHECK-constraint migration.
 */
enum SalaryFrequency: string
{
    case Monthly = 'monthly';
    case Weekly = 'weekly';
    case Daily = 'daily';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Weekly => 'Weekly',
            self::Daily => 'Daily',
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
