<?php

namespace App\Support\Hr;

use App\Enums\AssignmentType;
use App\Enums\WorkAssignmentStatus;
use App\Models\Admin;
use App\Models\AssignmentAbsence;
use App\Models\AssignmentCycleEvent;
use App\Models\EmployeeWorkAssignment;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;

/**
 * Replacement cycles (see the assignment_absences migration). The ORIGINAL Permanent assignment is never replaced while its holder is away: it stays
 * open under the same id, an absence window records the days it is not paid, and each step of the cycle is an event linked to it.
 *
 * One calendar day of the position is paid exactly once: to the original on the days they hold it, to a Replacement on the days the Replacement holds it,
 * and to nobody on a gap day. Payroll subtracts these windows (excludedRanges) together with the Replacements' own ranges, as a union, so the days are
 * never counted twice and never lost.
 */
final class AbsenceCycles
{
    /** Errors are 422 responses so the screens can show the message under the right field. */
    private static function fail(string $message, string $code, array $errors = []): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, 422, $errors === [] ? null : $errors));
    }

    public static function open(EmployeeWorkAssignment $assignment): ?AssignmentAbsence
    {
        return AssignmentAbsence::query()->where('work_assignment_id', $assignment->id)->whereNull('returned_on')->orderByDesc('id')->first();
    }

    /** The absence a Replacement was recorded in (its replacement_started event), whatever its state. */
    public static function ofReplacement(EmployeeWorkAssignment $replacement): ?AssignmentAbsence
    {
        $event = AssignmentCycleEvent::query()
            ->where('replacement_assignment_id', $replacement->id)
            ->where('event_type', AssignmentCycleEvent::REPLACEMENT_STARTED)
            ->latest('id')
            ->first();

        return $event === null ? null : AssignmentAbsence::query()->find($event->absence_id);
    }

    /**
     * Starts a cycle: records the absence (the day after the last working day onwards) and its first event. Nothing is written when a rule refuses it.
     */
    public static function start(EmployeeWorkAssignment $original, CarbonImmutable $lastDay, Admin $actor): AssignmentAbsence
    {
        if (self::open($original) !== null) {
            self::fail('This assignment is already marked as away. Record the return (or end the current cover) before starting another absence.', 'ABSENCE_ALREADY_OPEN');
        }

        // A cancelled cover closed its absence with zero days (returned_on = absent_from): it never took anything from the holder, so it cannot overlap.
        $earlier = AssignmentAbsence::query()->where('work_assignment_id', $original->id)->whereNotNull('returned_on')->whereColumn('returned_on', '>', 'absent_from')->orderByDesc('returned_on')->first();

        if ($earlier !== null && $lastDay->lt(CarbonImmutable::parse($earlier->returned_on)->startOfDay())) {
            self::fail("The person returned on {$earlier->returned_on->toDateString()} after an earlier absence; the next last working day ({$lastDay->toDateString()}) cannot be before that return.", 'ABSENCE_OVERLAPS_EARLIER_CYCLE', ['original_last_day' => ['The last working day must be on or after the earlier return date.']]);
        }

        $absence = AssignmentAbsence::query()->create([
            'work_assignment_id' => $original->id,
            'last_working_day' => $lastDay->toDateString(),
            'absent_from' => $lastDay->addDay()->toDateString(),
            'created_by' => $actor->id,
        ]);

        self::event($absence, AssignmentCycleEvent::ABSENCE_STARTED, $lastDay, $actor, null, ['absent_from' => $absence->absent_from->toDateString()]);

        return $absence;
    }

    /** @param  array<string, mixed>  $details */
    public static function event(AssignmentAbsence $absence, string $type, \DateTimeInterface $date, ?Admin $actor, ?int $replacementAssignmentId = null, array $details = []): AssignmentCycleEvent
    {
        return AssignmentCycleEvent::query()->create([
            'absence_id' => $absence->id,
            'work_assignment_id' => $absence->work_assignment_id,
            'event_type' => $type,
            'event_date' => CarbonImmutable::parse($date)->toDateString(),
            'replacement_assignment_id' => $replacementAssignmentId,
            'admin_id' => $actor?->id,
            'details' => $details === [] ? null : $details,
        ]);
    }

    /**
     * The days an assignment is NOT paid because its holder is away, as inclusive [from, to|null] ranges. $absences are the rows of that assignment.
     *
     * @param  iterable<int, AssignmentAbsence>  $absences
     * @return list<array{0: string, 1: ?string}>
     */
    public static function rangesOf(iterable $absences): array
    {
        $ranges = [];

        foreach ($absences as $absence) {
            $ranges[] = [
                $absence->absent_from->toDateString(),
                $absence->returned_on?->copy()->subDay()->toDateString(),
            ];
        }

        return $ranges;
    }

    /** @return list<array{0: string, 1: ?string}> */
    public static function excludedRanges(EmployeeWorkAssignment $assignment): array
    {
        return self::rangesOf(AssignmentAbsence::query()->where('work_assignment_id', $assignment->id)->orderBy('absent_from')->get());
    }

    /** True when $day falls inside one of the assignment's absence windows - the replaced assignment then is not "paid through" that day. */
    public static function isAwayOn(EmployeeWorkAssignment $assignment, \DateTimeInterface $day): bool
    {
        $iso = CarbonImmutable::parse($day)->toDateString();

        foreach (self::excludedRanges($assignment) as [$from, $to]) {
            if ($iso >= $from && ($to === null || $iso <= $to)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Replacements working at the position right now: non-cancelled Replacement-type successors that have not ended.
     *
     * @return Collection<int, EmployeeWorkAssignment>
     */
    public static function workingReplacements(EmployeeWorkAssignment $assignment): Collection
    {
        return EmployeeWorkAssignment::query()
            ->with('employee')
            ->where('replaces_assignment_id', $assignment->id)
            ->where('assignment_type', AssignmentType::Replacement->value)
            ->where('status', WorkAssignmentStatus::Active)
            ->orderBy('start_date')
            ->get();
    }

    /**
     * The open absence (holder away, original not ended) that a STILL WORKING Replacement was recorded in; null for any other assignment. Such a
     * Replacement is what keeps the position covered, so it may leave only through Return, a swap to another Replacement, or after the original ended.
     */
    public static function boundAbsence(EmployeeWorkAssignment $assignment): ?AssignmentAbsence
    {
        if (($assignment->assignment_type ?? AssignmentType::Permanent) !== AssignmentType::Replacement || $assignment->status !== WorkAssignmentStatus::Active) {
            return null;
        }

        $absence = self::ofReplacement($assignment);

        return $absence !== null && $absence->returned_on === null && $absence->terminated_on === null ? $absence : null;
    }

    /**
     * A covering Replacement was cancelled as a mistake: the absence is closed with zero days (the holder was never away for payroll) and the ledger
     * says so. Nothing is deleted; a corrected cover can be recorded afterwards with the same dates.
     */
    public static function cancelCover(AssignmentAbsence $absence, EmployeeWorkAssignment $cover, Admin $actor, string $reason): void
    {
        $absence->update(['returned_on' => $absence->absent_from->toDateString()]);

        self::event($absence, AssignmentCycleEvent::COVER_CANCELLED, $absence->absent_from, $actor, $cover->id, ['reason' => $reason]);
    }

    /**
     * Refuses an action that would end, cancel or re-type a Replacement that is covering an away holder and so strand the absence (the holder could
     * then be neither replaced again nor returned, and the position would silently stop costing anything).
     *
     * @throws HttpResponseException
     */
    public static function assertNotBound(EmployeeWorkAssignment $assignment, string $attempt): void
    {
        $absence = self::boundAbsence($assignment);

        if ($absence === null) {
            return;
        }

        $holder = $absence->workAssignment()->with('employee')->first()?->employee;
        $who = $holder === null ? 'the original employee' : "{$holder->full_name} ({$holder->employee_number})";

        self::fail("{$attempt} is not possible: this Replacement is covering {$who}, who has been away since {$absence->absent_from->toDateString()}, and the absence would stay open with nobody covering the position. When they are back use Return Original Employee; to hand the cover to someone else use Replace Employee on this Replacement; or end the original assignment first (the Replacement then keeps working).", 'REPLACEMENT_COVERS_AN_ABSENCE');
    }

    /**
     * Ending an assignment whose holder is away (Option B: allowed without waiting for the return). The Replacement is NEVER ended or changed by this:
     * when one is still working, HR has to see the consequence and confirm it. Nothing is written here.
     *
     * @throws HttpResponseException when the last day is before the absence began, or a working Replacement has not been confirmed
     */
    public static function assertTerminationAllowed(EmployeeWorkAssignment $assignment, CarbonImmutable $lastDay, bool $confirmed): ?AssignmentAbsence
    {
        $absence = self::open($assignment);

        if ($absence === null) {
            return null;
        }

        if ($lastDay->lt(CarbonImmutable::parse($absence->last_working_day)->startOfDay())) {
            self::fail("This assignment's holder has been away since {$absence->absent_from->toDateString()} (last working day {$absence->last_working_day->toDateString()}). The last day ({$lastDay->toDateString()}) cannot be before the last working day.", 'TERMINATION_BEFORE_LAST_WORKING_DAY', ['end_date' => ['The last day cannot be before the last working day.']]);
        }

        $working = self::workingReplacements($assignment);

        if ($working->isNotEmpty() && ! $confirmed) {
            $names = $working->map(fn (EmployeeWorkAssignment $r): string => trim(($r->employee?->full_name ?? 'Unknown').' ('.($r->employee?->employee_number ?? '#'.$r->id).') since '.$r->start_date->toDateString()))->implode('; ');

            throw new HttpResponseException(ApiResponse::error(
                "This employee is away and a Replacement is working at the position: {$names}. Ending this assignment does NOT end the Replacement - they keep working and are paid on the salary of this assignment, and the position will have no Permanent holder until HR makes another choice (Replace Employee, or make the Replacement Permanent). Confirm to end only this assignment.",
                'REPLACEMENT_CONTINUES_CONFIRMATION_REQUIRED',
                422,
                ['replacement_assignment_ids' => $working->pluck('id')->all(), 'replacements' => $names],
            ));
        }

        return $absence;
    }

    /** Records that the original was terminated while away; the absence stays open on purpose (nobody returns to a terminated assignment). */
    public static function recordTermination(AssignmentAbsence $absence, CarbonImmutable $lastDay, Admin $actor): void
    {
        $absence->update(['terminated_on' => $lastDay->toDateString()]);

        $working = self::workingReplacements($absence->workAssignment()->firstOrFail());

        self::event($absence, AssignmentCycleEvent::ORIGINAL_TERMINATED, $lastDay, $actor, null, [
            'replacement_assignment_ids' => $working->pluck('id')->all(),
            'replacements_left_working' => $working->isNotEmpty(),
        ]);
    }
}
