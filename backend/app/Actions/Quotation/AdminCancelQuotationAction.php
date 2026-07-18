<?php

namespace App\Actions\Quotation;

use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Enums\AuditAction;
use App\Enums\QuotationStatus;
use App\Events\Audit\AuditEvent;
use App\Events\Quotation\QuotationCancelled;
use App\Exceptions\Quotation\QuotationInvalidStateException;
use App\Models\Admin;
use App\Models\Quotation;
use Illuminate\Support\Facades\DB;

/**
 * Admin cancellation (Sprint 28): any non-terminal state → `cancelled`
 * (terminal). A reason is mandatory and recorded on the status history and
 * the audit trail. The system never uses Rejected/Declined.
 */
class AdminCancelQuotationAction
{
    public function __construct(private DashboardCacheInvalidatorInterface $dashboardCache) {}

    public function handle(Admin $admin, int $quotationId, string $reason): Quotation
    {
        [$quotation, $previousStatus] = DB::transaction(function () use ($admin, $quotationId, $reason): array {
            $quotation = Quotation::query()
                ->whereKey($quotationId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($quotation->status->isTerminal()) {
                throw QuotationInvalidStateException::forAction('This quotation is already closed.');
            }

            $previousStatus = $quotation->status;

            $quotation->update(['status' => QuotationStatus::Cancelled]);

            $quotation->statusHistories()->create([
                'status' => QuotationStatus::Cancelled,
                'changed_by_type' => 'admin',
                'changed_by_id' => $admin->id,
                'notes' => $reason,
            ]);

            DB::afterCommit(fn (): mixed => QuotationCancelled::dispatch($quotation));

            return [$quotation, $previousStatus];
        });

        event(AuditEvent::record(
            action: AuditAction::QuotationCancel,
            admin: $admin,
            description: 'Quotation cancelled.',
            entityType: Quotation::class,
            entityId: $quotation->id,
            metadata: [
                'quotation_number' => $quotation->quotation_number,
                'previous_status' => $previousStatus->value,
                'new_status' => QuotationStatus::Cancelled->value,
                'reason' => $reason,
            ],
        ));

        $this->dashboardCache->invalidate();

        return $quotation;
    }
}
