<?php

namespace App\Actions\Hr\Company;

use App\Enums\AuditAction;
use App\Enums\ClientStatus;
use App\Enums\WorkAssignmentStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\CompanyClosure;
use App\Models\CompanyClosureAssignment;
use App\Models\EmployeeWorkAssignment;
use App\Models\TemporaryReplacement;
use App\Support\ApiResponse;
use App\Support\Hr\AbsenceCycles;
use App\Support\Hr\Payroll\ClosedPayrollCorrection;
use App\Support\Hr\Payroll\CorrectionAuthority;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Closes a company / facility that stopped operating (owner decisions 2026-11-04).
 *
 *  - `effective_date` is the LAST day the company operates: every ACTIVE assignment of THIS company ends on that day (an assignment that had not started by
 *    then is cancelled, one that already ends earlier is left alone), so the employees drop out of this company's payroll from the next day on. Payroll of
 *    other months is calculated by the ordinary payroll from the assignments' dates, so a mid-month closure pays the real days of the real month.
 *  - Only this company's assignments change. An employee's assignments at other companies, their HR status and their history are untouched, and nothing is
 *    deleted: payroll, payments, assignments and audit stay.
 *  - Active Temporary covers are NOT ended - they are listed in the closure's summary for HR to review.
 *  - Exactly which assignments the closure ended / cancelled is kept (CompanyClosureAssignment) so a reopening restores only those.
 *  - If the closure reaches an approved or finalized payroll month it is recorded as a payroll correction like any other assignment change
 *    (ClosedPayrollCorrection: Super Admin or HR Manager, with a correction reason).
 */
class CloseCompanyAction
{
    public function __construct(private readonly ClosedPayrollCorrection $corrections = new ClosedPayrollCorrection) {}

    /** @param  array{effective_date: string, reason: string, correction_reason?: ?string}  $data */
    public function handle(ClientCompany $company, array $data, Admin $actor): CompanyClosure
    {
        if (! CorrectionAuthority::mayCloseCompany($actor)) {
            $this->fail('Only a Super Admin or an HR Manager can close a company. Nothing was changed.', 'COMPANY_CLOSE_NOT_ALLOWED', 403);
        }

        $reason = trim($data['reason']);

        if (mb_strlen($reason) < 5) {
            $this->fail('Enter the reason the company is closing (at least 5 characters). Nothing was changed.', 'CLOSURE_REASON_REQUIRED');
        }

        $effective = CarbonImmutable::parse($data['effective_date'])->startOfDay();

        // The assignments the closure will touch, read first so the closed-payroll check knows whose pay can change.
        $open = EmployeeWorkAssignment::query()->where('client_company_id', $company->id)->where('status', WorkAssignmentStatus::Active)->get();
        $affected = $open->filter(fn (EmployeeWorkAssignment $a): bool => $a->end_date === null || $a->end_date->gt($effective));
        $employeeIds = $affected->pluck('employee_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();

        return $this->corrections->run($actor, 'company_closed', $employeeIds, $effective->addDay()->toDateString(), $data['correction_reason'] ?? null, [
            'company_id' => $company->id,
            'old' => ['company_status' => $company->status->value],
            'new' => ['company_status' => ClientStatus::Inactive->value, 'effective_date' => $effective->toDateString()],
        ], fn (): CompanyClosure => DB::transaction(function () use ($company, $actor, $reason, $effective): CompanyClosure {
            $company = ClientCompany::query()->lockForUpdate()->findOrFail($company->id);

            if (CompanyClosure::query()->where('client_company_id', $company->id)->where('status', CompanyClosure::STATUS_CLOSED)->exists()) {
                $this->fail("{$company->name} is already closed.", 'COMPANY_ALREADY_CLOSED');
            }

            $closure = CompanyClosure::query()->create([
                'client_company_id' => $company->id,
                'effective_date' => $effective->toDateString(),
                'reason' => $reason,
                'status' => CompanyClosure::STATUS_CLOSED,
                'closed_by' => $actor->id,
                'closed_by_role' => $actor->role->value,
                'previous_company_status' => $company->status->value,
            ]);

            $ended = 0;
            $cancelled = 0;
            $left = 0;

            $assignments = EmployeeWorkAssignment::query()->where('client_company_id', $company->id)->where('status', WorkAssignmentStatus::Active)->lockForUpdate()->get();

            foreach ($assignments as $assignment) {
                $previousEnd = $assignment->end_date?->toDateString();

                if ($assignment->start_date->gt($effective)) {
                    // Never started before the company closed: it is cancelled, not given an end date before its own start.
                    $assignment->update(['status' => WorkAssignmentStatus::Cancelled, 'notes' => trim(($assignment->notes ? $assignment->notes."\n" : '')."Cancelled: {$company->name} closed on {$effective->toDateString()}.")]);
                    $this->remember($closure, $assignment, CompanyClosureAssignment::CANCELLED, $previousEnd, null);
                    $cancelled++;

                    continue;
                }

                if ($assignment->end_date !== null && ! $assignment->end_date->gt($effective)) {
                    $left++;

                    continue;
                }

                // A holder who is away at closure: the absence cycle is closed with the same last day (the Replacement ends with the company too).
                $absence = AbsenceCycles::open($assignment);
                $assignment->update(['status' => WorkAssignmentStatus::Ended, 'end_date' => $effective->toDateString(), 'notes' => trim(($assignment->notes ? $assignment->notes."\n" : '')."Ended: {$company->name} closed on {$effective->toDateString()}.")]);

                if ($absence !== null) {
                    AbsenceCycles::recordTermination($absence, $effective, $actor);
                }

                $this->remember($closure, $assignment, CompanyClosureAssignment::ENDED, $previousEnd, $effective->toDateString());
                $ended++;
            }

            $covers = $this->coversToReview($company, $effective);

            $company->update(['status' => ClientStatus::Inactive]);
            $summary = ['assignments_ended' => $ended, 'assignments_cancelled' => $cancelled, 'assignments_left_as_they_were' => $left, 'covers_to_review' => $covers];
            $closure->update(['summary' => $summary]);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Company '{$company->name}' closed, effective {$effective->toDateString()}: {$ended} assignment(s) ended, {$cancelled} cancelled, ".count($covers).' Temporary cover(s) to review. '.$reason,
                entityType: ClientCompany::class,
                entityId: $company->id,
                metadata: ['event' => 'company_closed', 'closure_id' => $closure->id, 'effective_date' => $effective->toDateString(), 'reason' => $reason, 'closed_by_role' => $actor->role->value, 'previous_company_status' => $closure->previous_company_status, ...$summary],
            ));

            return $closure->refresh();
        }));
    }

    private function remember(CompanyClosure $closure, EmployeeWorkAssignment $assignment, string $action, ?string $previousEnd, ?string $newEnd): void
    {
        CompanyClosureAssignment::query()->create([
            'company_closure_id' => $closure->id,
            'work_assignment_id' => $assignment->id,
            'action' => $action,
            'previous_status' => WorkAssignmentStatus::Active->value,
            'previous_end_date' => $previousEnd,
            'new_end_date' => $newEnd,
        ]);
    }

    /**
     * Temporary covers still running on this company's positions after the closing day. They are NOT changed (owner decision); HR reviews them.
     *
     * @return list<array<string, mixed>>
     */
    private function coversToReview(ClientCompany $company, CarbonImmutable $effective): array
    {
        return TemporaryReplacement::query()
            ->whereIn('work_assignment_id', EmployeeWorkAssignment::query()->where('client_company_id', $company->id)->select('id'))
            ->whereNotIn('status', ['ended', 'cancelled', 'expired', 'rejected'])
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>', $effective->toDateString()))
            ->get(['id', 'work_assignment_id', 'replacement_employee_id', 'start_date', 'end_date', 'status'])
            ->map(fn (TemporaryReplacement $c): array => ['cover_id' => $c->id, 'position_assignment_id' => $c->work_assignment_id, 'replacement_employee_id' => $c->replacement_employee_id, 'start_date' => $c->start_date?->toDateString(), 'end_date' => $c->end_date?->toDateString(), 'status' => is_object($c->status) ? $c->status->value : $c->status])
            ->values()->all();
    }

    private function fail(string $message, string $code, int $status = 422): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, $status));
    }
}
