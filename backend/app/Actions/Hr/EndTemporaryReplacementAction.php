<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\TemporaryReplacementStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\TemporaryReplacement;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

class EndTemporaryReplacementAction
{
    public function handle(TemporaryReplacement $replacement, string $endDate, ?string $notes, Admin $actor): TemporaryReplacement
    {
        if ($replacement->status !== TemporaryReplacementStatus::Active) {
            throw new HttpResponseException(
                ApiResponse::error(
                    'This temporary replacement has already ended.',
                    'TEMPORARY_REPLACEMENT_NOT_ACTIVE',
                    422,
                ),
            );
        }

        $replacement->update([
            'end_date' => $endDate,
            'status' => TemporaryReplacementStatus::Ended,
            'notes' => $notes ?? $replacement->notes,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Temporary replacement #{$replacement->id} coverage ended.",
            entityType: TemporaryReplacement::class,
            entityId: $replacement->id,
        ));

        return $replacement->load('workAssignment.employee', 'workAssignment.workLocation.clientCompany', 'replacementEmployee', 'createdBy', 'payments.paidBy');
    }
}
