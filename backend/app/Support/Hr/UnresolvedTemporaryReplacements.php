<?php

namespace App\Support\Hr;

use App\Enums\AssignmentType;
use App\Enums\AuditAction;
use App\Enums\WorkAssignmentStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\EmployeeWorkAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * An unresolved record: HR was warned that a TEMPORARY worker was being registered as a Replacement, confirmed it, and the employee is still
 * classified as Temporary. Payroll pays a Temporary worker only through verified Temporary cover, never a salary line, so for such a record:
 *  - no payroll line is calculated or created for its days (PayrollRunCalculator);
 *  - the payroll month cannot be generated until the classification is corrected (GeneratePayrollRunAction);
 *  - the record is flagged on the company screen.
 * Nothing converts the assignment type by itself. The correction (a different employee type, or the replacement cancelled) is an explicit action,
 * and the moment it happens an audit event records who corrected it and how.
 */
final class UnresolvedTemporaryReplacements
{
    /** @return Builder<EmployeeWorkAssignment> */
    public static function query(?string $period = null): Builder
    {
        $query = EmployeeWorkAssignment::query()
            ->with(['employee', 'clientCompany'])
            ->where('assignment_type', AssignmentType::Replacement->value)
            ->where('status', '!=', WorkAssignmentStatus::Cancelled)
            ->whereHas('employee', fn ($e) => $e->where('employment_type', 'temporary'));

        if ($period !== null) {
            $first = CarbonImmutable::parse($period.'-01')->startOfDay();
            $query->whereDate('start_date', '<=', $first->endOfMonth()->toDateString())
                ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $first->toDateString()));
        }

        return $query->orderBy('id');
    }

    public static function isUnresolved(EmployeeWorkAssignment $assignment): bool
    {
        $assignment->loadMissing('employee');

        return ($assignment->assignment_type ?? AssignmentType::Permanent) === AssignmentType::Replacement
            && $assignment->status !== WorkAssignmentStatus::Cancelled
            && $assignment->employee?->employment_type === 'temporary';
    }

    public const REASON = 'Registered as a Replacement although the employee is a Temporary worker. No payroll line is calculated for these days. Correct the employee type, or cancel this Replacement and record Temporary cover, before payroll is calculated.';

    /** @param  Collection<int, EmployeeWorkAssignment>  $assignments */
    public static function describe(Collection $assignments): string
    {
        return $assignments->take(5)->map(fn (EmployeeWorkAssignment $a): string => "{$a->employee?->employee_number} {$a->employee?->full_name} (assignment #{$a->id}, from {$a->start_date->toDateString()})")->implode('; ')
            .($assignments->count() > 5 ? '; and '.($assignments->count() - 5).' more' : '');
    }

    /**
     * Records that an unresolved record was corrected, by whom and how. Called by the actions that can correct it, only when the record really was
     * unresolved before the change and is not any more.
     *
     * @param  array<string, mixed>  $details
     */
    public static function auditResolved(EmployeeWorkAssignment $assignment, string $method, Admin $actor, array $details = []): void
    {
        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Temporary worker registered as Replacement - corrected on assignment #{$assignment->id} ({$method}).",
            entityType: EmployeeWorkAssignment::class,
            entityId: $assignment->id,
            metadata: [
                'event' => 'temporary_registered_as_replacement_resolved',
                'method' => $method,
                'employee_id' => $assignment->employee_id,
                'assignment_id' => $assignment->id,
                ...$details,
            ],
        ));
    }
}
