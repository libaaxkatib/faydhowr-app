<?php

namespace App\Support\Hr;

use App\Enums\WorkAssignmentStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Support\ApiResponse;
use App\Support\Hr\Facility\FacilityCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The one rule about turning a Permanent employee into a Temporary one (docs/HR_PAYROLL_RULES.md U1, final).
 *
 * Payroll gives a Temporary employee no payroll line of their own (their pay is the cover cost). Changing the flag of someone who still holds an
 * ACTIVE assignment would therefore make their pay line vanish. So the change is refused while any assignment of theirs is Active; HR must first end
 * it through the approved workflow (Stop / End, which asks for the replacement) and assign someone to the position. Nothing is ever ended, replaced,
 * re-typed or recalculated here on HR's behalf, and no record is deleted.
 *
 * Once every assignment has properly ended the change is allowed. Months already calculated keep their lines: approved and finalized runs are stored,
 * and for months not yet approved the payroll calculator keeps calculating assignments that ended on or before the day of the change
 * (temporarySince) as the Permanent assignments they were.
 */
final class EmploymentTypeChange
{
    /** The owner-approved Somali warning, word for word. */
    public const MESSAGE = 'Shaqaalahaan wuxuu leeyahay assignment Permanent ah oo weli firfircoon. Si looga fogaado in xogta mushaharkiisu lunto, marka hore jooji shaqadii hore, qof kale u qoondee booskaas, kadibna samee assignment-ka cusub ee Temporary. Nidaamku si toos ah uma beddelayo shaqada hore.';

    public const CODE = 'EMPLOYMENT_TYPE_CHANGE_BLOCKED_ACTIVE_ASSIGNMENT';

    /**
     * @throws HttpResponseException 422 when the employee is Permanent and still holds an Active assignment
     */
    public static function assertAllowed(Employee $employee, string $newType): void
    {
        if ($newType !== 'temporary' || ($employee->employment_type ?? 'permanent') === 'temporary') {
            return;
        }

        $active = EmployeeWorkAssignment::query()
            ->with(['clientCompany', 'workLocation.clientCompany'])
            ->where('employee_id', $employee->id)
            ->where('status', WorkAssignmentStatus::Active)
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        if ($active->isEmpty()) {
            return;
        }

        throw new HttpResponseException(ApiResponse::error(self::MESSAGE, self::CODE, 422, [
            'assignments' => $active->map(fn (EmployeeWorkAssignment $a): array => [
                'id' => $a->id,
                'assignment_type' => $a->assignment_type?->value,
                'company' => ($a->clientCompany ?? $a->workLocation?->clientCompany)?->name,
                'start_date' => $a->start_date->toDateString(),
                'end_date' => $a->end_date?->toDateString(),
                'shift' => $a->shift?->value,
            ])->all(),
        ]));
    }

    /**
     * The business-calendar day (Mogadishu) on which this employee was last changed to Temporary, from the audit trail; null when no such change was
     * ever recorded (the person was registered as Temporary from the start).
     */
    public static function temporarySince(Employee $employee): ?string
    {
        $events = AuditLog::query()
            ->where('entity_type', Employee::class)
            ->where('entity_id', $employee->id)
            ->orderByDesc('id')
            ->get(['metadata', 'created_at']);

        foreach ($events as $event) {
            $change = is_array($event->metadata) ? ($event->metadata['employment_type'] ?? null) : null;

            if (is_array($change) && ($change['to'] ?? null) === 'temporary') {
                return CarbonImmutable::parse($event->created_at, 'UTC')->setTimezone(FacilityCalendar::TIMEZONE)->toDateString();
            }
        }

        return null;
    }

    /** Whether an assignment of a (now) Temporary employee is one they held as a Permanent: it ended on or before the day they became Temporary. */
    public static function wasHeldAsPermanent(Employee $employee, EmployeeWorkAssignment $assignment): bool
    {
        if ($assignment->end_date === null) {
            return false;
        }

        $since = self::temporarySince($employee);

        return $since !== null && $assignment->end_date->toDateString() <= $since;
    }
}
