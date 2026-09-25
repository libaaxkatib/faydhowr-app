<?php

namespace App\Enums;

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 4: a small categorical rating - no
 * invented numeric/weighted scoring, per the same caution the roadmap
 * applied to candidate matching in Phase 2.
 */
enum PerformanceRating: string
{
    case Excellent = 'excellent';
    case Good = 'good';
    case NeedsImprovement = 'needs_improvement';
    case Poor = 'poor';

    public function label(): string
    {
        return match ($this) {
            self::Excellent => 'Excellent',
            self::Good => 'Good',
            self::NeedsImprovement => 'Needs Improvement',
            self::Poor => 'Poor',
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
