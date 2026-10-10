<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\TemporaryReplacement;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * A replacement's cost is deducted from the covered Permanent employee's pay
 * only after it is approved (a separate permission from recording workdays).
 */
class ApproveTemporaryReplacementAction
{
    public function handle(TemporaryReplacement $replacement, Admin $actor): TemporaryReplacement
    {
        if ($replacement->approved_at !== null) {
            throw new HttpResponseException(ApiResponse::error('This replacement is already approved.', 'REPLACEMENT_ALREADY_APPROVED', 422));
        }

        $replacement->update(['approved_at' => now(), 'approved_by' => $actor->id]);

        event(AuditEvent::record(
            action: AuditAction::Approve,
            admin: $actor,
            description: "Temporary replacement #{$replacement->id} approved for payroll deduction.",
            entityType: TemporaryReplacement::class,
            entityId: $replacement->id,
        ));

        return $replacement->load('workAssignment.employee', 'workAssignment.workLocation.clientCompany', 'workAssignment.clientCompany', 'replacementEmployee', 'createdBy', 'payments.paidBy');
    }
}
