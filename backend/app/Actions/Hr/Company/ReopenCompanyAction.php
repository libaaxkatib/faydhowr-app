<?php

namespace App\Actions\Hr\Company;

use App\Enums\AuditAction;
use App\Enums\ClientStatus;
use App\Enums\EmployeeStatus;
use App\Enums\WorkAssignmentStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\CompanyClosure;
use App\Models\CompanyClosureAssignment;
use App\Models\EmployeeWorkAssignment;
use App\Support\ApiResponse;
use App\Support\Hr\AbsenceCycles;
use App\Support\Hr\ConcurrentAssignmentGuard;
use App\Support\Hr\CoverConflicts;
use App\Support\Hr\Payroll\ClosedPayrollCorrection;
use App\Support\Hr\Payroll\CorrectionAuthority;
use App\Support\Hr\PositionSuccession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Reopens a company that was closed by mistake (or that started operating again) - owner decisions 2026-11-04.
 *
 * The company becomes active again, and ONLY the assignments that THIS closure ended or cancelled can be restored - never any other assignment, never one
 * the closure did not touch. Each is checked for conflicts first (preview()): it must still be as the closure left it, the employee must still be Active, the
 * position must not have been given to someone else since, and the employee must not now hold an overlapping assignment here or somewhere the concurrent-
 * assignment rule forbids. The person reopening CONFIRMS which restorable assignments to bring back (`restore_assignment_ids`); the rest stay ended and
 * HR can assign people again in the ordinary way. Restored days that reach an approved or finalized payroll month are a recorded payroll correction.
 */
class ReopenCompanyAction
{
    public function __construct(private readonly ClosedPayrollCorrection $corrections = new ClosedPayrollCorrection) {}

    public static function openClosure(ClientCompany $company): ?CompanyClosure
    {
        return CompanyClosure::query()->where('client_company_id', $company->id)->where('status', CompanyClosure::STATUS_CLOSED)->latest('id')->first();
    }

    /**
     * What a reopening would do, assignment by assignment: restorable or the reason it cannot be restored.
     *
     * @return list<array<string, mixed>>
     */
    public static function preview(CompanyClosure $closure): array
    {
        $rows = [];

        foreach ($closure->assignments()->with(['assignment.employee', 'assignment.position'])->orderBy('id')->get() as $entry) {
            $assignment = $entry->assignment;
            $conflict = $entry->restored_at !== null ? 'Already restored.' : self::conflict($entry, $assignment);

            $rows[] = [
                'closure_assignment_id' => $entry->id,
                'assignment_id' => $assignment->id,
                'employee_id' => $assignment->employee_id,
                'employee_name' => $assignment->employee?->full_name,
                'employee_number' => $assignment->employee?->employee_number,
                'position' => $assignment->position?->name,
                'action' => $entry->action,
                'ended_on' => $entry->new_end_date?->toDateString(),
                'restores_to_end_date' => $entry->previous_end_date?->toDateString(),
                'restorable' => $conflict === null,
                'conflict' => $conflict,
            ];
        }

        return $rows;
    }

    /** @param  array{reason: string, restore_assignment_ids?: list<int>, correction_reason?: ?string}  $data */
    public function handle(ClientCompany $company, array $data, Admin $actor): CompanyClosure
    {
        if (! CorrectionAuthority::mayCloseCompany($actor)) {
            $this->fail('Only a Super Admin or an HR Manager can reopen a company. Nothing was changed.', 'COMPANY_CLOSE_NOT_ALLOWED', 403);
        }

        $reason = trim($data['reason']);

        if (mb_strlen($reason) < 5) {
            $this->fail('Enter the reason for reopening the company (at least 5 characters). Nothing was changed.', 'CLOSURE_REASON_REQUIRED');
        }

        $closure = self::openClosure($company);

        if ($closure === null) {
            $this->fail("{$company->name} is not closed.", 'COMPANY_NOT_CLOSED');
        }

        $chosen = array_values(array_unique(array_map('intval', $data['restore_assignment_ids'] ?? [])));
        $entries = $closure->assignments()->with('assignment')->whereIn('work_assignment_id', $chosen ?: [0])->get();

        if ($entries->count() !== count($chosen)) {
            $this->fail('Some of the assignments to restore do not belong to this closure. Nothing was changed.', 'RESTORE_NOT_OF_CLOSURE');
        }

        $employeeIds = $entries->map(fn (CompanyClosureAssignment $e): int => (int) $e->assignment->employee_id)->unique()->values()->all();
        $earliest = $entries->map(fn (CompanyClosureAssignment $e): string => $e->new_end_date?->addDay()->toDateString() ?? $e->assignment->start_date->toDateString())->min() ?? $closure->effective_date->addDay()->toDateString();

        return $this->corrections->run($actor, 'company_reopened', $employeeIds, $earliest, $data['correction_reason'] ?? null, [
            'company_id' => $company->id,
            'old' => ['company_status' => $company->status->value],
            'new' => ['company_status' => ClientStatus::Active->value, 'restored_assignments' => $chosen],
        ], fn (): CompanyClosure => DB::transaction(function () use ($company, $closure, $chosen, $reason, $actor): CompanyClosure {
            $company = ClientCompany::query()->lockForUpdate()->findOrFail($company->id);
            $closure = CompanyClosure::query()->lockForUpdate()->findOrFail($closure->id);

            if ($closure->status !== CompanyClosure::STATUS_CLOSED) {
                $this->fail("{$company->name} was already reopened.", 'COMPANY_NOT_CLOSED');
            }

            $restored = [];
            $skipped = [];

            foreach ($closure->assignments()->with('assignment.employee')->whereIn('work_assignment_id', $chosen ?: [0])->orderBy('id')->get() as $entry) {
                // Lock order for every Replacement/cover writer: the Replacement's own row, then the position row a cover locks (O-1).
                if ($entry->assignment->replaces_assignment_id !== null) {
                    EmployeeWorkAssignment::query()->whereKey($entry->assignment->id)->lockForUpdate()->first();
                    EmployeeWorkAssignment::query()->whereKey($entry->assignment->replaces_assignment_id)->lockForUpdate()->first();
                }

                $conflict = $entry->restored_at !== null ? 'Already restored.' : self::conflict($entry, $entry->assignment);

                if ($conflict !== null) {
                    // Confirmed but no longer possible: nothing is changed; the whole reopening is refused so the person decides again with the real picture.
                    $this->fail("Cannot restore {$entry->assignment->employee?->full_name}: {$conflict} Nothing was changed.", 'RESTORE_CONFLICT', 422, ['assignment_id' => $entry->work_assignment_id, 'conflict' => $conflict]);
                }

                $entry->assignment->update(['status' => WorkAssignmentStatus::Active, 'end_date' => $entry->previous_end_date?->toDateString(), 'notes' => trim(($entry->assignment->notes ? $entry->assignment->notes."\n" : '')."Restored: {$company->name} reopened. {$reason}")]);
                $entry->update(['restored_at' => now(), 'restored_by' => $actor->id]);
                $restored[] = $entry->work_assignment_id;
            }

            foreach ($closure->assignments()->whereNull('restored_at')->pluck('work_assignment_id') as $id) {
                $skipped[] = (int) $id;
            }

            $company->update(['status' => ClientStatus::from($closure->previous_company_status ?? ClientStatus::Active->value)]);
            $summary = ['assignments_restored' => $restored, 'assignments_left_ended' => $skipped];
            $closure->update(['status' => CompanyClosure::STATUS_REOPENED, 'reopened_at' => now(), 'reopened_by' => $actor->id, 'reopen_reason' => $reason, 'reopen_summary' => $summary]);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Company '{$company->name}' reopened: ".count($restored).' assignment(s) restored, '.count($skipped).' left ended. '.$reason,
                entityType: ClientCompany::class,
                entityId: $company->id,
                metadata: ['event' => 'company_reopened', 'closure_id' => $closure->id, 'reason' => $reason, 'reopened_by_role' => $actor->role->value, ...$summary],
            ));

            return $closure->refresh();
        }));
    }

    /** Why this closure-ended assignment cannot be restored right now, or null when it can. */
    private static function conflict(CompanyClosureAssignment $entry, EmployeeWorkAssignment $assignment): ?string
    {
        $assignment->loadMissing('employee');
        $employee = $assignment->employee;

        $unchanged = $entry->action === CompanyClosureAssignment::CANCELLED
            ? $assignment->status === WorkAssignmentStatus::Cancelled
            : ($assignment->status === WorkAssignmentStatus::Ended && $assignment->end_date?->toDateString() === $entry->new_end_date?->toDateString());

        if (! $unchanged) {
            return 'The assignment was changed after the closure.';
        }

        if ($employee === null || $employee->trashed() || $employee->status !== EmployeeStatus::Active) {
            return 'The employee is no longer an Active employee.';
        }

        if (PositionSuccession::successorsOf($assignment)->exists()) {
            return 'The position was given to someone else after the closure.';
        }

        // Owner decision O-1 (symmetrical): a Replacement is not given back its days when a Temporary cover of the same position has them since.
        // Only the days it gets back are judged (those after the day the closure ended it); a cancelled one gets all its days back.
        $from = $entry->action === CompanyClosureAssignment::CANCELLED || $entry->new_end_date === null ? $assignment->start_date->toDateString() : $entry->new_end_date->addDay()->toDateString();
        $coverInTheWay = CoverConflicts::findCoverOverlappingReplacement($assignment, $from, $entry->previous_end_date?->toDateString(), 'restored');

        if ($coverInTheWay !== null) {
            return trim(str_replace('Nothing was changed.', '', $coverInTheWay['message']));
        }

        // Owner rule A7: nor may the days it gets back be days another Replacement of the same position holds now.
        $sibling = PositionSuccession::replacementOverlapConflict($assignment, $from, $entry->previous_end_date?->toDateString(), 'restored');

        if ($sibling !== null) {
            return $sibling['message'];
        }

        // Owner decision F-A: a Replacement of a holder who has returned is never given back a last day on or after that return.
        $runsPastReturn = AbsenceCycles::replacementReturnConflict($assignment, $entry->previous_end_date?->toDateString());

        if ($runsPastReturn !== null) {
            return $runsPastReturn['message'];
        }

        $clash = EmployeeWorkAssignment::query()
            ->where('employee_id', $assignment->employee_id)->where('client_company_id', $assignment->client_company_id)
            ->where('status', WorkAssignmentStatus::Active)->where('id', '!=', $assignment->id)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $assignment->start_date->toDateString()))
            ->exists();

        if ($clash) {
            return 'The employee now holds another active assignment at this company.';
        }

        try {
            ConcurrentAssignmentGuard::assertAllowed($employee, (int) $assignment->client_company_id, CarbonImmutable::parse($assignment->start_date->toDateString())->startOfDay(), $entry->previous_end_date === null ? null : CarbonImmutable::parse($entry->previous_end_date->toDateString())->startOfDay(), $assignment->id);
        } catch (HttpResponseException $e) {
            return (string) (json_decode($e->getResponse()->getContent(), true)['message'] ?? 'The employee works somewhere the concurrent-assignment rule does not allow.');
        }

        return null;
    }

    private function fail(string $message, string $code, int $status = 422, ?array $errors = null): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, $status, $errors));
    }
}
