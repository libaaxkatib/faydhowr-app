<?php

namespace App\Support\Hr;

use App\Enums\AssignmentShift;
use App\Enums\WorkAssignmentStatus;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\TemporaryReplacement;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The two "never at the same time" rules for Temporary work (docs/HR_PAYROLL_RULES.md U1 and U12). A refusal here is the ONLY consequence: nothing is
 * cancelled, replaced, re-typed or recalculated on HR's behalf, and no salary or payroll record is touched.
 *
 *   U12  A Temporary cover is refused when its dates overlap another Temporary cover
 *        - of the SAME position (a position has one holder at a time), whatever the shift; or
 *        - of the SAME Temporary employee on another position, unless the two positions are on different recorded shifts.
 *   U1   A Temporary cover is refused when the Temporary employee already has an assignment of their own (Permanent or Replacement, not cancelled)
 *        whose dates overlap it, unless the two are on different recorded shifts. The refusal is written in Somali.
 *
 * Dates are compared as whole calendar days and are INCLUSIVE at both ends (a cover ending on the 10th and another starting on the 10th overlap;
 * ending on the 9th, they do not). An open end means "no end yet". A cover is only compared over the days its position exists (the covered
 * assignment's own dates), exactly like the coverage period itself.
 *
 * Shifts: the system records only Day / Night per assignment, never clock times. Two work periods may share a calendar day ONLY when BOTH shifts are
 * recorded and different. A missing shift, or the same shift, is never assumed to be different: the answer is "they overlap", and the message tells HR
 * what to correct (record the shifts) - fail safely.
 */
final class CoverConflicts
{
    public const CODE_POSITION = 'TEMPORARY_COVER_OVERLAPS_POSITION';

    public const CODE_EMPLOYEE = 'TEMPORARY_COVER_OVERLAPS_EMPLOYEE';

    public const CODE_OWN_ASSIGNMENT = 'TEMPORARY_EMPLOYEE_HAS_OVERLAPPING_ASSIGNMENT';

    /** Two work periods on the same calendar day can only be told apart when both shifts are recorded and different. */
    public static function sameTime(?AssignmentShift $a, ?AssignmentShift $b): bool
    {
        return $a === null || $b === null || $a === $b;
    }

    /**
     * Whether giving $worker the cover of $position from $start to $end (open end = null) is allowed. Null when it is, otherwise the refusal:
     * ['code', 'message', 'conflicts' => [...]]. Read-only.
     *
     * @param  ?int  $ignoreCoverId  the cover being edited/ended: it is never a conflict with itself
     * @return ?array{code: string, message: string, conflicts: list<array<string, mixed>>}
     */
    public static function find(EmployeeWorkAssignment $position, Employee $worker, string $start, ?string $end, ?int $ignoreCoverId = null): ?array
    {
        $period = self::within($start, $end, $position);

        if ($period === null) {
            return null; // the cover lies entirely outside the position's own dates: it covers no day, so it can conflict with none
        }

        return self::ownAssignment($position, $worker, $period) ?? self::otherCover($position, $worker, $period, $ignoreCoverId);
    }

    /**
     * @throws HttpResponseException 422 with the refusal's code, message and the conflicting record(s)
     */
    public static function assertFree(EmployeeWorkAssignment $position, Employee $worker, string $start, ?string $end, ?int $ignoreCoverId = null): void
    {
        $conflict = self::find($position, $worker, $start, $end, $ignoreCoverId);

        if ($conflict !== null) {
            throw new HttpResponseException(ApiResponse::error($conflict['message'], $conflict['code'], 422, ['conflicts' => $conflict['conflicts']]));
        }
    }

    /**
     * @throws HttpResponseException 422 when the person's own assignment would overlap a Temporary cover of theirs (see findForOwnAssignment)
     */
    public static function assertOwnAssignmentFree(Employee $worker, string $start, ?string $end, ?AssignmentShift $shift): void
    {
        $conflict = self::findForOwnAssignment($worker, $start, $end, $shift);

        if ($conflict !== null) {
            throw new HttpResponseException(ApiResponse::error($conflict['message'], $conflict['code'], 422, ['conflicts' => $conflict['conflicts']]));
        }
    }

    /**
     * Editing a covered position (its dates or its shift) re-checks the covers it carries against the NEW values; the edited position itself is never
     * a "second" assignment. `$position` must already hold the new values (it is not saved here).
     *
     * @throws HttpResponseException 422 naming the first cover that would now clash
     */
    public static function assertCoversStillFree(EmployeeWorkAssignment $position): void
    {
        foreach (TemporaryReplacement::query()->with('replacementEmployee')->where('work_assignment_id', $position->id)->orderBy('start_date')->orderBy('id')->get() as $cover) {
            self::assertFree($position, $cover->replacementEmployee, $cover->start_date->toDateString(), $cover->end_date?->toDateString(), $cover->id);
        }
    }

    /**
     * The reverse of U1: giving $worker an assignment of their own (dates and shift as given) while a Temporary cover of theirs overlaps it. Null when free.
     *
     * @return ?array{code: string, message: string, conflicts: list<array<string, mixed>>}
     */
    public static function findForOwnAssignment(Employee $worker, string $start, ?string $end, ?AssignmentShift $shift): ?array
    {
        $covers = TemporaryReplacement::query()->with(['workAssignment.clientCompany', 'workAssignment.workLocation.clientCompany', 'workAssignment.employee', 'replacementEmployee'])
            ->where('replacement_employee_id', $worker->id)
            ->get();

        foreach ($covers as $cover) {
            $position = $cover->workAssignment;
            $theirs = self::within($cover->start_date->toDateString(), $cover->end_date?->toDateString(), $position);

            if ($theirs === null || ! self::overlap($theirs, [$start, $end]) || ! self::sameTime($shift, $position->shift)) {
                continue;
            }

            return [
                'code' => self::CODE_OWN_ASSIGNMENT,
                'message' => self::somaliCoverInTheWay(self::describeCover($cover), $shift, $position->shift),
                'conflicts' => [self::coverRow($cover)],
            ];
        }

        return null;
    }

    /** @param  array{0: string, 1: ?string}  $period */
    private static function ownAssignment(EmployeeWorkAssignment $position, Employee $worker, array $period): ?array
    {
        $own = EmployeeWorkAssignment::query()->with(['clientCompany', 'workLocation.clientCompany'])
            ->where('employee_id', $worker->id)
            ->where('status', '!=', WorkAssignmentStatus::Cancelled)
            ->where('id', '!=', $position->id)
            ->orderBy('start_date')
            ->get();

        foreach ($own as $assignment) {
            $theirs = [$assignment->start_date->toDateString(), $assignment->end_date?->toDateString()];

            if (! self::overlap($period, $theirs) || ! self::sameTime($position->shift, $assignment->shift)) {
                continue;
            }

            return [
                'code' => self::CODE_OWN_ASSIGNMENT,
                'message' => self::somaliWarning(self::describeAssignment($assignment), $position->shift, $assignment->shift),
                'conflicts' => [[
                    'kind' => 'assignment',
                    'id' => $assignment->id,
                    'assignment_type' => $assignment->assignment_type?->value,
                    'start_date' => $theirs[0],
                    'end_date' => $theirs[1],
                    'shift' => $assignment->shift?->value,
                    'company' => self::company($assignment),
                ]],
            ];
        }

        return null;
    }

    /** @param  array{0: string, 1: ?string}  $period */
    private static function otherCover(EmployeeWorkAssignment $position, Employee $worker, array $period, ?int $ignoreCoverId): ?array
    {
        $covers = TemporaryReplacement::query()->with(['workAssignment.employee', 'workAssignment.clientCompany', 'workAssignment.workLocation.clientCompany', 'replacementEmployee'])
            ->where(fn ($q) => $q->where('work_assignment_id', $position->id)->orWhere('replacement_employee_id', $worker->id))
            ->when($ignoreCoverId !== null, fn ($q) => $q->where('id', '!=', $ignoreCoverId))
            ->orderBy('start_date')->orderBy('id')
            ->get();

        foreach ($covers as $other) {
            $otherPosition = $other->workAssignment;
            $theirs = self::within($other->start_date->toDateString(), $other->end_date?->toDateString(), $otherPosition);

            if ($theirs === null || ! self::overlap($period, $theirs)) {
                continue;
            }

            if ((int) $other->work_assignment_id === (int) $position->id) {
                return [
                    'code' => self::CODE_POSITION,
                    'message' => 'This position already has a Temporary cover over some of these days: '.self::describeCover($other).' A position has one Temporary at a time. End or correct that cover first, or choose dates that do not overlap it.',
                    'conflicts' => [self::coverRow($other)],
                ];
            }

            if (! self::sameTime($position->shift, $otherPosition->shift)) {
                continue; // the same Temporary on two DIFFERENT recorded shifts of the same calendar days
            }

            return [
                'code' => self::CODE_EMPLOYEE,
                'message' => "{$worker->full_name} ({$worker->employee_number}) already covers another position over some of these days: ".self::describeCover($other)
                    .' One person cannot be paid for two positions at the same time. Choose dates that do not overlap, or - if the two positions are on different shifts - record the shift (Day / Night) on both assignments.',
                'conflicts' => [self::coverRow($other)],
            ];
        }

        return null;
    }

    /**
     * The days a cover really exists: its own dates cut to the position's dates. Null when no day is left.
     *
     * @return ?array{0: string, 1: ?string}
     */
    private static function within(string $start, ?string $end, EmployeeWorkAssignment $position): ?array
    {
        $from = max($start, $position->start_date->toDateString());
        $to = $end;
        $positionEnd = $position->end_date?->toDateString();

        if ($positionEnd !== null && ($to === null || $positionEnd < $to)) {
            $to = $positionEnd;
        }

        return $to !== null && $to < $from ? null : [$from, $to];
    }

    /**
     * @param  array{0: string, 1: ?string}  $a
     * @param  array{0: string, 1: ?string}  $b
     */
    private static function overlap(array $a, array $b): bool
    {
        return ($a[1] === null || $b[0] <= $a[1]) && ($b[1] === null || $a[0] <= $b[1]);
    }

    private static function company(EmployeeWorkAssignment $assignment): ?string
    {
        return ($assignment->clientCompany ?? $assignment->workLocation?->clientCompany)?->name;
    }

    private static function describeCover(TemporaryReplacement $cover): string
    {
        $position = $cover->workAssignment;
        $until = $cover->end_date?->toDateString() ?? 'no end date yet';
        $where = self::company($position) ?? 'the office';

        return "cover #{$cover->id} by {$cover->replacementEmployee?->full_name} ({$cover->replacementEmployee?->employee_number}) of {$position->employee?->full_name} at {$where}, from {$cover->start_date->toDateString()} until {$until}.";
    }

    private static function describeAssignment(EmployeeWorkAssignment $assignment): string
    {
        $until = $assignment->end_date?->toDateString() ?? 'aan dhammaad lahayn';

        return "#{$assignment->id}, ".(self::company($assignment) ?? 'xafiiska').", laga bilaabo {$assignment->start_date->toDateString()} ilaa {$until}";
    }

    /** @return array<string, mixed> */
    private static function coverRow(TemporaryReplacement $cover): array
    {
        return [
            'kind' => 'cover',
            'id' => $cover->id,
            'work_assignment_id' => $cover->work_assignment_id,
            'replacement_employee_id' => $cover->replacement_employee_id,
            'start_date' => $cover->start_date->toDateString(),
            'end_date' => $cover->end_date?->toDateString(),
            'shift' => $cover->workAssignment?->shift?->value,
            'company' => $cover->workAssignment !== null ? self::company($cover->workAssignment) : null,
        ];
    }

    private static function shiftSomali(?AssignmentShift $shift): string
    {
        return match ($shift) {
            AssignmentShift::Day => 'maalinta (Day)',
            AssignmentShift::Night => 'habeenka (Night)',
            default => 'lama qorin',
        };
    }

    /**
     * The owner-approved Somali warning (U1), followed by the facts HR needs: which assignment is in the way and what each shift says.
     */
    private static function somaliWarning(string $assignment, ?AssignmentShift $thisShift, ?AssignmentShift $otherShift): string
    {
        return 'Shaqaalahaan wuxuu hore ugu jiraa shaqo Permanent ah oo waqtigeedu isku dhacayo shaqadan. Haddii aad rabto inaad shaqadan Temporary ahaan ugu wareejiso, marka hore jooji/cancel garee shaqadii hore, kadibna qof kale u qoondee booskii hore. Shift-yo kala duwan waa la oggol yahay haddii waqtigoodu isma saarna.'
            ." Shaqada hore: {$assignment}.".self::shiftFacts($thisShift, $otherShift);
    }

    /** The same rule seen from the other side: the employee already covers a position and is now being given an assignment of their own. */
    private static function somaliCoverInTheWay(string $cover, ?AssignmentShift $thisShift, ?AssignmentShift $otherShift): string
    {
        return 'Shaqaalahaan hore ayuu ugu jiraa shaqo Temporary ah (bedel) oo waqtigeedu isku dhacayo shaqadan cusub. Marka hore soo gaabi ama jooji bedelkii Temporary-ga ah, ama saxdo taariikhaha. Shift-yo kala duwan waa la oggol yahay haddii waqtigoodu isma saarna.'
            ." Bedelka hore: {$cover}".self::shiftFacts($thisShift, $otherShift);
    }

    private static function shiftFacts(?AssignmentShift $thisShift, ?AssignmentShift $otherShift): string
    {
        $message = ' Shift-ka shaqadan: '.self::shiftSomali($thisShift).'; shift-ka shaqada kale: '.self::shiftSomali($otherShift).'.';

        if ($thisShift === null || $otherShift === null) {
            $message .= ' Shift-ka mid ka mid ah ama labadaba lama qorin, sidaas darteed lama xaqiijin karo inaysan isku dhicin. Fadlan ku qor shift-ka (Maalin/Habeen) labada shaqo, ama saxdo taariikhaha.';
        } elseif ($thisShift === $otherShift) {
            $message .= ' Labada shaqo shift-kooda waa isku mid, sidaas darteed waqtiga way isku dhacayaan.';
        }

        return $message;
    }
}
