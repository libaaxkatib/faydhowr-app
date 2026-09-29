<?php

namespace App\Enums;

/**
 * Data Issues & Reconciliation Center — lifecycle of one recorded issue.
 * Never auto-transitioned by the system; a human always chooses the status.
 */
enum DataIssueStatus: string
{
    case Open = 'open';
    case Investigating = 'investigating';
    case Resolved = 'resolved';
    case AcceptedDifference = 'accepted_difference';
    case CannotResolve = 'cannot_resolve';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Investigating => 'Investigating',
            self::Resolved => 'Resolved',
            self::AcceptedDifference => 'Accepted Difference',
            self::CannotResolve => 'Cannot Resolve',
        };
    }

    /** Statuses that record resolved_by/resolved_at — an issue is "closed" once it reaches one of these. */
    public function isClosing(): bool
    {
        return match ($this) {
            self::Resolved, self::AcceptedDifference, self::CannotResolve => true,
            self::Open, self::Investigating => false,
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
