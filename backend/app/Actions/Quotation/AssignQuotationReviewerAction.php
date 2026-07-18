<?php

namespace App\Actions\Quotation;

use App\Enums\AuditAction;
use App\Enums\QuotationStatus;
use App\Events\Audit\AuditEvent;
use App\Exceptions\Quotation\QuotationInvalidStateException;
use App\Models\Admin;
use App\Models\Quotation;
use Illuminate\Support\Facades\DB;

/**
 * Single-reviewer assignment (Sprint 28): the first assignment transitions
 * `submitted` → `under_review`; reassignment is allowed at any non-terminal
 * point after submit and never changes status. Every assignment is audited.
 */
class AssignQuotationReviewerAction
{
    public function handle(Admin $admin, int $quotationId, int $assignedAdminId): Quotation
    {
        [$quotation, $previousStatus, $previousReviewerId] = DB::transaction(function () use ($quotationId, $assignedAdminId): array {
            $quotation = Quotation::query()
                ->whereKey($quotationId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($quotation->status === QuotationStatus::Draft || $quotation->status->isTerminal()) {
                throw QuotationInvalidStateException::forAction('A reviewer cannot be assigned in the current quotation status.');
            }

            $previousStatus = $quotation->status;
            $previousReviewerId = $quotation->assigned_admin_id;

            $quotation->assigned_admin_id = $assignedAdminId;

            if ($quotation->status === QuotationStatus::Submitted) {
                $quotation->status = QuotationStatus::UnderReview;
            }

            $quotation->save();

            if ($previousStatus === QuotationStatus::Submitted) {
                $quotation->statusHistories()->create([
                    'status' => QuotationStatus::UnderReview,
                    'changed_by_type' => 'admin',
                    'changed_by_id' => $assignedAdminId,
                    'notes' => null,
                ]);
            }

            return [$quotation->load('assignedAdmin'), $previousStatus, $previousReviewerId];
        });

        event(AuditEvent::record(
            action: AuditAction::QuotationAssign,
            admin: $admin,
            description: 'Quotation reviewer assigned.',
            entityType: Quotation::class,
            entityId: $quotation->id,
            metadata: [
                'quotation_number' => $quotation->quotation_number,
                'previous_status' => $previousStatus->value,
                'new_status' => $quotation->status->value,
                'previous_reviewer_id' => $previousReviewerId,
                'assigned_admin_id' => $quotation->assigned_admin_id,
            ],
        ));

        return $quotation;
    }
}
