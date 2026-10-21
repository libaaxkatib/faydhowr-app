<?php

namespace App\Enums;

/**
 * Where a complaint stands. ANSWERED is not RESOLVED: entering a response never changes the status by itself, and moving to
 * "answered" or "resolved" is always an explicit step that is recorded in the complaint's history.
 */
enum FacilityComplaintStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Answered = 'answered';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'New / open',
            self::InProgress => 'In progress',
            self::Answered => 'Answered',
            self::Resolved => 'Resolved',
        };
    }

    /**
     * The steps a person may take from this status. A resolved complaint can only be reopened (and that is recorded); nothing skips history.
     *
     * @return list<self>
     */
    public function nextAllowed(): array
    {
        return match ($this) {
            self::Open => [self::InProgress, self::Answered, self::Resolved],
            self::InProgress => [self::Open, self::Answered, self::Resolved],
            self::Answered => [self::InProgress, self::Resolved],
            self::Resolved => [self::Open],
        };
    }

    /** Complaints that still need attention. */
    public function isUnresolved(): bool
    {
        return $this !== self::Resolved;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
