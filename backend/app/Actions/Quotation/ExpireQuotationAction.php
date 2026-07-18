<?php

namespace App\Actions\Quotation;

use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Enums\AuditAction;
use App\Enums\QuotationStatus;
use App\Events\Audit\AuditEvent;
use App\Events\Quotation\QuotationExpired;
use App\Exceptions\Quotation\QuotationInvalidStateException;
use App\Models\Admin;
use App\Models\Quotation;
use Illuminate\Support\Facades\DB;

/**
 * Manual expiry (Sprint 28): `quotation_ready` / `under_discussion` →
 * `expired`. Expired is not terminal — a later admin revision revives the
 * quotation to `quotation_ready`.
 */
class ExpireQuotationAction
{
    public function __construct(private DashboardCacheInvalidatorInterface $dashboardCache) {}

    public function handle(Admin $admin, int $quotationId): Quotation
    {
        [$quotation, $previousStatus] = DB::transaction(function () use ($admin, $quotationId): array {
            $quotation = Quotation::query()
                ->whereKey($quotationId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($quotation->status, [
                QuotationStatus::QuotationReady,
                QuotationStatus::UnderDiscussion,
            ], true)) {
                throw QuotationInvalidStateException::forAction('Only issued quotations can be expired.');
            }

            $previousStatus = $quotation->status;

            $quotation->update(['status' => QuotationStatus::Expired]);

            $quotation->statusHistories()->create([
                'status' => QuotationStatus::Expired,
                'changed_by_type' => 'admin',
                'changed_by_id' => $admin->id,
                'notes' => null,
            ]);

            DB::afterCommit(fn (): mixed => QuotationExpired::dispatch($quotation));

            return [$quotation, $previousStatus];
        });

        event(AuditEvent::record(
            action: AuditAction::QuotationExpire,
            admin: $admin,
            description: 'Quotation expired.',
            entityType: Quotation::class,
            entityId: $quotation->id,
            metadata: [
                'quotation_number' => $quotation->quotation_number,
                'previous_status' => $previousStatus->value,
                'new_status' => QuotationStatus::Expired->value,
            ],
        ));

        $this->dashboardCache->invalidate();

        return $quotation;
    }
}
