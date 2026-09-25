<?php

namespace App\Enums;

/**
 * docs/HRM_MARKETING_SRS.md HR §10-11: Pass/Fail/Pending are the legacy
 * values, kept for backward compatibility with any pre-Phase-1 data (see
 * the migration that widens this column's CHECK constraint). New Practical
 * Decisions exclusively use the three canonical outcomes going forward -
 * Approved, Rejected, KuCelisPractical - enforced at the FormRequest layer
 * (StorePracticalAssessmentRequest).
 */
enum PracticalAssessmentResult: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case KuCelisPractical = 'ku_celis_practical';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
