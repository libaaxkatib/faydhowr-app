<?php

namespace App\Support\Hr;

use App\Enums\WorkAssignmentStatus;
use App\Models\EmployeeWorkAssignment;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
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

        if ($predecessor->end_date === null) {
            self::fail('The assignment being replaced is still open. Record the separation (or end that assignment) first so its last paid day is known.', 'SUCCESSION_PREDECESSOR_STILL_OPEN');
        }

        $taken = EmployeeWorkAssignment::query()
            ->where('replaces_assignment_id', $predecessor->id)
            ->where('status', '!=', WorkAssignmentStatus::Cancelled)
            ->when($ignoreSuccessorId, fn ($q) => $q->where('id', '!=', $ignoreSuccessorId))
            ->exists();

        if ($taken) {
            self::fail('Another employee already takes over this assignment.', 'SUCCESSION_ALREADY_REPLACED');
        }

        self::assertNoOverlap($predecessor->end_date, $successorStart);
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
        $successor = EmployeeWorkAssignment::query()
            ->where('replaces_assignment_id', $assignment->id)
            ->where('status', '!=', WorkAssignmentStatus::Cancelled)
            ->orderBy('start_date')
            ->first();

        if ($successor !== null) {
            self::assertNoOverlap($newLastDay, CarbonImmutable::parse($successor->start_date));
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
