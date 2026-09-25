<?php

namespace App\Enums;

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 3 (Workforce Operations): a minimal,
 * defensible reason set - the SRS has no existing separation vocabulary to
 * reconcile against, so this stays small rather than inventing a detailed
 * HR taxonomy nobody asked for.
 */
enum EmployeeSeparationReason: string
{
    case Resigned = 'resigned';
    case Terminated = 'terminated';
    case ContractEnded = 'contract_ended';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Resigned => 'Resigned',
            self::Terminated => 'Terminated',
            self::ContractEnded => 'Contract Ended',
            self::Other => 'Other',
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
