<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeePipelineStage;
use App\Enums\EmployeeStatus;
use App\Enums\PracticalAssessmentResult;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeePracticalAssessment;
use App\Models\EmployeeStatusHistory;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * docs/HRM_MARKETING_SRS.md HR §10-11: exactly three outcomes. Ku Celis
 * Practical is NOT a rejection — it sends the employee back to Need
 * Practical, creates a new attempt, and preserves every previous attempt
 * untouched. Approved routes straight to Waiting (status), skipping the
 * legacy 'approved'/'recruitment'/'practical' status values entirely, per
 * the Phase 1 plan's status/pipeline_stage split.
 */
class RecordPracticalDecisionAction
{
    public function handle(
        Employee $employee,
        PracticalAssessmentResult $decision,
        string $assessmentDate,
        ?int $practicalBatchId,
        ?string $notes,
        Admin $actor,
    ): Employee {
        if (! in_array($employee->pipeline_stage, [EmployeePipelineStage::NeedPractical, EmployeePipelineStage::PracticalRepeat], true)) {
            throw new HttpResponseException(
                ApiResponse::error(
                    "Employee '{$employee->full_name}' is not currently eligible for a practical decision (must be in the Need Practical queue).",
                    'EMPLOYEE_NOT_ELIGIBLE_FOR_PRACTICAL',
                    422,
                ),
            );
        }

        return DB::transaction(function () use ($employee, $decision, $assessmentDate, $practicalBatchId, $notes, $actor) {
            $attemptNumber = $employee->practicalAssessments()->count() + 1;

            EmployeePracticalAssessment::query()->create([
                'employee_id' => $employee->id,
                'practical_batch_id' => $practicalBatchId,
                'attempt_number' => $attemptNumber,
                'assessed_by' => $actor->id,
                'assessment_date' => $assessmentDate,
                'result' => $decision,
                'notes' => $notes,
            ]);

            match ($decision) {
                PracticalAssessmentResult::Approved => $this->approve($employee, $actor),
                PracticalAssessmentResult::Rejected => $employee->update(['pipeline_stage' => EmployeePipelineStage::Rejected]),
                PracticalAssessmentResult::KuCelisPractical => $employee->update(['pipeline_stage' => EmployeePipelineStage::PracticalRepeat]),
                default => null,
            };

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Practical decision '{$decision->value}' recorded for employee '{$employee->full_name}' (attempt #{$attemptNumber}).",
                entityType: Employee::class,
                entityId: $employee->id,
                metadata: ['decision' => $decision->value, 'attempt_number' => $attemptNumber],
            ));

            return $employee->fresh(['practicalAssessments.assessedBy', 'category', 'department', 'position']);
        });
    }

    private function approve(Employee $employee, Admin $actor): void
    {
        $from = $employee->status;

        $employee->update([
            'pipeline_stage' => null,
            'status' => EmployeeStatus::Waiting,
            'waiting_since' => now(),
        ]);

        EmployeeStatusHistory::query()->create([
            'employee_id' => $employee->id,
            'from_status' => $from,
            'to_status' => EmployeeStatus::Waiting,
            'changed_by' => $actor->id,
            'note' => 'Practical approved.',
        ]);
    }
}
