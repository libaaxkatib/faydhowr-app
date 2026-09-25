<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeePerformanceReview;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

class RecordEmployeePerformanceReviewAction
{
    public function handle(Employee $employee, array $data, Admin $actor): Employee
    {
        if ($employee->status !== EmployeeStatus::Active) {
            throw new HttpResponseException(
                ApiResponse::error(
                    "Employee '{$employee->full_name}' must be Active to record a performance review.",
                    'EMPLOYEE_NOT_ACTIVE',
                    422,
                ),
            );
        }

        $review = EmployeePerformanceReview::query()->create([
            ...$data,
            'employee_id' => $employee->id,
            'reviewed_by' => $actor->id,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: "Performance review recorded for '{$employee->full_name}' ({$review->rating->label()}).",
            entityType: Employee::class,
            entityId: $employee->id,
            metadata: ['review_id' => $review->id],
        ));

        return $employee->load('performanceReviews.reviewedBy');
    }
}
