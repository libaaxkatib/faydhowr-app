<?php

namespace App\Support\Hr;

use App\Enums\AdminRole;
use App\Enums\EmployeeContractStatus;
use App\Enums\EmployeeRegistrationOrigin;
use App\Enums\EmployeeStatus;
use App\Enums\EmployeeUniformStatus;
use App\Enums\PracticalAssessmentResult;
use App\Enums\TrainingParticipantResult;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\TrainingBatchParticipant;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The strict registration workflow of a NEW registration, enforced on the server for every path (screen, direct API, bulk
 * activation, waiting-list import, company assignment, rehire).
 *
 * A new person is eligible for the Waiting List, Active status or a company only after ALL of these records exist - the records
 * themselves are checked, not a flag or a stage name:
 *   1. guarantor    a guarantor record that HR verified
 *   2. contract     a contract that was signed (or verified)
 *   3. uniform      a uniform that was confirmed
 *   4. training     a training batch participation that was completed
 *   5. practice     the latest practical assessment is Approved
 * The ORDER is enforced where each record is created: every stage action only works from the stage before it (guarantor ->
 * contract -> uniform -> training -> practice), so a later record cannot be produced before an earlier one.
 *
 * Only a NEW registration is held to this. Existing records (registered before the workflow) and office staff are never blocked
 * by it - existing records are managed one by one with MoveExistingEmployeeToStageAction.
 *
 * The only way around it for a new registration is a Super Admin override with a written reason (>= 10 characters), recorded on the
 * status history (source "override") and in the audit log. Nobody else can bypass it.
 */
final class EmployeeRegistrationWorkflow
{
    /** Statuses that mean "past the registration workflow". */
    private const PROTECTED_STATUSES = [EmployeeStatus::Waiting, EmployeeStatus::Approved, EmployeeStatus::Active];

    public function appliesTo(Employee $employee): bool
    {
        return $this->originOf($employee) === EmployeeRegistrationOrigin::New;
    }

    public function originOf(Employee $employee): EmployeeRegistrationOrigin
    {
        $origin = $employee->registration_origin;

        return $origin instanceof EmployeeRegistrationOrigin ? $origin : (EmployeeRegistrationOrigin::tryFrom((string) $origin) ?? EmployeeRegistrationOrigin::New);
    }

    public function protects(EmployeeStatus $to): bool
    {
        return in_array($to, self::PROTECTED_STATUSES, true);
    }

    /**
     * @return list<array{key: string, label: string, done: bool}>
     */
    public function steps(Employee $employee): array
    {
        $guarantor = $employee->guarantor()->first();
        $latestAssessment = $employee->practicalAssessments()->reorder()->orderByDesc('attempt_number')->orderByDesc('id')->first();

        return [
            ['key' => 'guarantor', 'label' => 'Guarantor verified', 'done' => $guarantor !== null && $guarantor->verified_at !== null],
            ['key' => 'contract', 'label' => 'Contract signed', 'done' => $employee->contracts()->whereIn('status', [EmployeeContractStatus::Signed->value, EmployeeContractStatus::Verified->value])->exists()],
            ['key' => 'uniform', 'label' => 'Uniform confirmed', 'done' => $employee->uniform()->where('status', EmployeeUniformStatus::Confirmed->value)->exists()],
            ['key' => 'training', 'label' => 'Training completed', 'done' => TrainingBatchParticipant::query()->where('employee_id', $employee->id)->where('result', TrainingParticipantResult::Completed->value)->exists()],
            ['key' => 'practice', 'label' => 'Practical approved', 'done' => $latestAssessment !== null && $latestAssessment->result === PracticalAssessmentResult::Approved],
        ];
    }

    /** @return list<string> labels of the steps that are not done yet */
    public function missing(Employee $employee): array
    {
        return array_values(array_map(
            fn (array $s): string => $s['label'],
            array_filter($this->steps($employee), fn (array $s): bool => ! $s['done']),
        ));
    }

    public function isComplete(Employee $employee): bool
    {
        return $this->missing($employee) === [];
    }

    /** A Super Admin override with a reason was recorded that moved this person past the workflow. */
    public function isExempted(Employee $employee): bool
    {
        return EmployeeStatusHistory::query()
            ->where('employee_id', $employee->id)
            ->where('source', 'override')
            ->whereNotNull('override_reason')
            ->whereIn('to_status', array_map(fn (EmployeeStatus $s): string => $s->value, self::PROTECTED_STATUSES))
            ->exists();
    }

    /** True when this person is a new registration that has not completed the workflow and has no recorded override. */
    public function blocks(Employee $employee): bool
    {
        return $this->appliesTo($employee) && ! $this->isComplete($employee) && ! $this->isExempted($employee);
    }

    /** What the screen shows: the origin and, for a new registration, each step. */
    public function summary(Employee $employee): array
    {
        $origin = $this->originOf($employee);
        $applies = $origin === EmployeeRegistrationOrigin::New;
        $steps = $applies ? $this->steps($employee) : [];
        $complete = $applies ? ! in_array(false, array_column($steps, 'done'), true) : true;
        $exempted = $applies && ! $complete && $this->isExempted($employee);

        return [
            'origin' => $origin->value,
            'origin_label' => $origin->label(),
            'applies' => $applies,
            'complete' => $complete,
            'override_recorded' => $exempted,
            'eligible_for_waiting_and_company' => ! $applies || $complete || $exempted,
            'steps' => $steps,
            'missing' => array_values(array_map(fn (array $s): string => $s['label'], array_filter($steps, fn (array $s): bool => ! $s['done']))),
        ];
    }

    /**
     * For a status change into Waiting / Approved / Active. Returns the override reason actually used (null when the person is
     * allowed through without one). Refuses anyone but a Super Admin with a written reason.
     *
     * @throws HttpResponseException
     */
    public function authorizeStatusChange(Employee $employee, EmployeeStatus $to, Admin $actor, ?string $overrideReason): ?string
    {
        if (! $this->protects($to) || ! $this->blocks($employee)) {
            return null;
        }

        $missing = implode(', ', $this->missing($employee));
        $reason = $overrideReason === null ? '' : trim(preg_replace('/\s+/u', ' ', $overrideReason) ?? '');

        if ($actor->role !== AdminRole::SuperAdmin) {
            if ($reason !== '') {
                $this->fail('Only a Super Admin can override the registration workflow.', 'TRANSITION_OVERRIDE_FORBIDDEN', 403);
            }

            $this->fail($this->message($employee, "cannot become {$to->label()}", $missing), 'REGISTRATION_WORKFLOW_INCOMPLETE');
        }

        if (mb_strlen($reason) < EmployeeTransitionGuard::MIN_REASON_LENGTH) {
            $this->fail(
                $this->message($employee, "cannot become {$to->label()}", $missing).' As a Super Admin you can override this, but a reason of at least '.EmployeeTransitionGuard::MIN_REASON_LENGTH.' characters is required.',
                'REGISTRATION_WORKFLOW_OVERRIDE_REASON_REQUIRED',
            );
        }

        if (mb_strlen($reason) > EmployeeTransitionGuard::MAX_REASON_LENGTH) {
            $this->fail('The override reason may not be longer than '.EmployeeTransitionGuard::MAX_REASON_LENGTH.' characters.', 'TRANSITION_OVERRIDE_REASON_TOO_LONG');
        }

        return $reason;
    }

    /**
     * For a path that has no override at all (waiting-list import, rehire, company assignment): a blocked person is refused.
     *
     * @throws HttpResponseException
     */
    public function requireEligible(Employee $employee, string $what, string $code = 'REGISTRATION_WORKFLOW_INCOMPLETE'): void
    {
        if ($this->blocks($employee)) {
            $this->fail($this->message($employee, $what, implode(', ', $this->missing($employee))), $code);
        }
    }

    private function message(Employee $employee, string $what, string $missing): string
    {
        return "{$employee->full_name} ({$employee->employee_number}) is a new registration that has not completed the registration workflow, so the person {$what}. Still missing: {$missing}.";
    }

    private function fail(string $message, string $code, int $status = 422): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, $status));
    }
}
