<?php

namespace App\Support\Hr;

use App\Enums\AssignmentType;
use App\Enums\WorkAssignmentStatus;
use App\Models\EmployeeWorkAssignment;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Rules for a PERMANENT succession (a new Permanent employee takes over a post
 * from one who left). Fayadhowr operates every calendar day, so each calendar
 * day of the post is paid to exactly one person:
 *
 *   - the leaver is paid up to and including the separation (assignment end) date;
 *   - the successor is paid from their own start date, inclusive;
 *   - overlap (successor start on or before the leaver's last day) would pay
 *     two people for the same day, so it is refused;
 *   - a gap (days between the two) is allowed because nobody is paid for it,
 *     but it is reported so HR can see the post was uncovered.
 *
 * This is deliberately separate from Temporary replacement, which covers a
 * still-employed person and is charged through the workday ledger.
 */
final class PositionSuccession
{
    /**
     * Checks a successor (starting on $successorStart) may follow $predecessor.
     * $ignoreSuccessorId lets an existing successor be re-validated after an edit.
     */
    public static function assertCanFollow(EmployeeWorkAssignment $predecessor, int $successorEmployeeId, CarbonImmutable $successorStart, ?int $ignoreSuccessorId = null): void
    {
        if ($predecessor->status === WorkAssignmentStatus::Cancelled) {
            self::fail('The assignment being replaced was cancelled, so it cannot be succeeded.', 'SUCCESSION_PREDECESSOR_CANCELLED');
        }

        if ($predecessor->employee_id === $successorEmployeeId) {
            self::fail('An employee cannot take over their own assignment.', 'SUCCESSION_SAME_EMPLOYEE');
        }

        // A Permanent assignment whose holder is away stays open (replacement cycles): its last paid day is the last working day of the open absence.
        $absence = $predecessor->end_date === null ? AbsenceCycles::open($predecessor) : null;

        if ($predecessor->end_date === null && $absence === null) {
            self::fail('The assignment being replaced is still open. Record the separation (or end that assignment) first so its last paid day is known.', 'SUCCESSION_PREDECESSOR_STILL_OPEN');
        }

        // While the holder is away only a Replacement that is still working blocks another; once the assignment has an end date, every successor blocks
        // except the Replacements of FINISHED cover cycles (the holder came back): those are history (see successorsOf).
        $taken = self::successorsOf($predecessor, holderAway: $absence !== null)
            ->when($ignoreSuccessorId, fn ($q) => $q->where('id', '!=', $ignoreSuccessorId))
            ->exists();

        if ($taken) {
            self::fail('Another employee already takes over this assignment.', 'SUCCESSION_ALREADY_REPLACED');
        }

        self::assertNoOverlap($predecessor->end_date ?? $absence->last_working_day, $successorStart);
    }

    /**
     * The assignments that count as taking over $predecessor (the "someone already takes over this assignment" rule). Cancelled ones never count.
     *  - holder away (open absence): only a Replacement that is still Active counts;
     *  - otherwise: every non-cancelled successor counts - a Permanent successor, a Replacement still working, a cover kept because the holder was ended
     *    while away, a Replacement with no cycle record - EXCEPT a Replacement of a finished cover cycle that has ended (AbsenceCycles::
     *    finishedCycleReplacementIds): its holder returned, so it is history. One that is still Active always counts.
     *
     * @return Builder<EmployeeWorkAssignment>
     */
    public static function successorsOf(EmployeeWorkAssignment $predecessor, bool $holderAway = false): Builder
    {
        return EmployeeWorkAssignment::query()
            ->where('replaces_assignment_id', $predecessor->id)
            ->when($holderAway, fn ($q) => $q->where('status', WorkAssignmentStatus::Active), fn ($q) => $q
                ->where('status', '!=', WorkAssignmentStatus::Cancelled)
                ->where(fn ($w) => $w->whereNotIn('id', AbsenceCycles::finishedCycleReplacementIds())->orWhere('status', WorkAssignmentStatus::Active)));
    }

    /** The predecessor's last paid day must be strictly before the successor's first. */
    public static function assertNoOverlap(\DateTimeInterface $predecessorLastDay, CarbonImmutable $successorStart): void
    {
        $last = CarbonImmutable::parse($predecessorLastDay)->startOfDay();

        if ($successorStart->lte($last)) {
            $days = (int) abs($successorStart->diffInDays($last)) + 1;
            self::fail(
                "The replacement would start on {$successorStart->toDateString()}, but the person being replaced is paid up to and including {$last->toDateString()}: {$days} day(s) would be paid twice. The replacement must start after the last paid day.",
                'SUCCESSION_OVERLAP',
            );
        }
    }

    /**
     * Before moving an assignment's last day, make sure its successor (if any)
     * does not start on or before that day.
     */
    public static function assertEndKeepsSuccessorClear(EmployeeWorkAssignment $assignment, CarbonImmutable $newLastDay): void
    {
        $successors = EmployeeWorkAssignment::query()
            ->where('replaces_assignment_id', $assignment->id)
            ->where('status', '!=', WorkAssignmentStatus::Cancelled)
            ->orderBy('start_date')
            ->get();

        foreach ($successors as $successor) {
            // A Replacement that took over while the holder was away does not overlap the holder's last paid day: the days it holds are not paid to the
            // holder (the absence window). Ending the holder's assignment later therefore leaves it untouched.
            if ($successor->assignment_type === AssignmentType::Replacement && AbsenceCycles::isAwayOn($assignment, $successor->start_date)) {
                continue;
            }

            self::assertNoOverlap($newLastDay, CarbonImmutable::parse($successor->start_date));

            return;
        }
    }

    /** Whole uncovered calendar days between the two (0 when back to back). */
    public static function gapDays(\DateTimeInterface $predecessorLastDay, \DateTimeInterface $successorStart): int
    {
        $last = CarbonImmutable::parse($predecessorLastDay)->startOfDay();
        $start = CarbonImmutable::parse($successorStart)->startOfDay();

        return max(0, (int) $last->diffInDays($start) - 1);
    }

    private static function fail(string $message, string $code): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, 422));
    }
}
