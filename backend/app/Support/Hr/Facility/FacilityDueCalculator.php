<?php

namespace App\Support\Hr\Facility;

use App\Enums\FacilityDueState;
use Carbon\CarbonImmutable;

/**
 * Pure rules for "when is this company/workplace next due, and what is its state today?". No database, no clock of its own: the caller
 * passes today's date. Nothing here sends anything - there is no scheduler in production - it only answers when asked.
 *
 * Next due date:
 *   1. the follow-up date the inspector wrote on the LAST counted inspection, when there is one (the inspector's explicit decision), else
 *   2. last inspection date + the schedule's frequency in days, when a frequency is set, else
 *   3. none.
 *
 * State, in this order:
 *   never inspected (no counted inspection)  ->  NeverInspected
 *   no next due date                          ->  NoSchedule
 *   today after the due date                  ->  Overdue
 *   today is the due date                     ->  Due
 *   due date within the next `dueSoonDays`    ->  DueSoon
 *   otherwise                                 ->  Ok
 */
final class FacilityDueCalculator
{
    public const DEFAULT_DUE_SOON_DAYS = 7;

    /**
     * @return array{state: FacilityDueState, next_due_on: ?CarbonImmutable, days_until_due: ?int, days_since_last: ?int}
     */
    public static function evaluate(
        CarbonImmutable $today,
        ?CarbonImmutable $lastInspectedOn,
        ?CarbonImmutable $lastFollowUpOn,
        ?int $frequencyDays,
        int $dueSoonDays = self::DEFAULT_DUE_SOON_DAYS,
    ): array {
        $today = $today->startOfDay();

        if ($lastInspectedOn === null) {
            return ['state' => FacilityDueState::NeverInspected, 'next_due_on' => null, 'days_until_due' => null, 'days_since_last' => null];
        }

        $last = $lastInspectedOn->startOfDay();
        $nextDue = $lastFollowUpOn?->startOfDay()
            ?? ($frequencyDays !== null && $frequencyDays > 0 ? $last->addDays($frequencyDays) : null);
        $sinceLast = (int) $last->diffInDays($today, false);

        if ($nextDue === null) {
            return ['state' => FacilityDueState::NoSchedule, 'next_due_on' => null, 'days_until_due' => null, 'days_since_last' => $sinceLast];
        }

        $untilDue = (int) $today->diffInDays($nextDue, false);
        $state = match (true) {
            $untilDue < 0 => FacilityDueState::Overdue,
            $untilDue === 0 => FacilityDueState::Due,
            $untilDue <= $dueSoonDays => FacilityDueState::DueSoon,
            default => FacilityDueState::Ok,
        };

        return ['state' => $state, 'next_due_on' => $nextDue, 'days_until_due' => $untilDue, 'days_since_last' => $sinceLast];
    }
}
