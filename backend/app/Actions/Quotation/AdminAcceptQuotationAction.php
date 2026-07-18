<?php

namespace App\Actions\Quotation;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Acceptance on the customer's behalf (Sprint 28, offline agreement). Reuses
 * the customer acceptance core — latest-revision validation, payment policy
 * snapshot, and Sprint 27 booking auto-acceptance — with the admin recorded
 * as the acting party and a mandatory reason on the audit trail.
 */
class AdminAcceptQuotationAction
{
    public function __construct(private AcceptQuotationAction $acceptQuotation) {}

    public function handle(Admin $admin, int $quotationId, ?int $revisionId, string $reason): Quotation
    {
        $existing = Quotation::query()
            ->with('customerProfile')
            ->whereKey($quotationId)
            ->first();

        if ($existing === null || $existing->customerProfile === null) {
            throw new ModelNotFoundException;
        }

        $previousStatus = $existing->status->value;

        $quotation = $this->acceptQuotation->handle(
            $existing->customerProfile,
            $quotationId,
            revisionId: $revisionId,
            actingAdmin: $admin,
        );

        event(AuditEvent::record(
            action: AuditAction::QuotationAdminAccept,
            admin: $admin,
            description: 'Quotation accepted on behalf of the customer.',
            entityType: Quotation::class,
            entityId: $quotation->id,
            metadata: [
                'quotation_number' => $quotation->quotation_number,
                'version_number' => $quotation->latestRevision?->version_number,
                'previous_status' => $previousStatus,
                'new_status' => $quotation->status->value,
                'reason' => $reason,
            ],
        ));

        return $quotation;
    }
}
