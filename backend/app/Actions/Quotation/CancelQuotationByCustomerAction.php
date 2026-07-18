<?php

namespace App\Actions\Quotation;

use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Enums\QuotationStatus;
use App\Events\Quotation\QuotationCancelled;
use App\Exceptions\Quotation\QuotationInvalidStateException;
use App\Models\CustomerProfile;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Customer cancellation is allowed only pre-pricing (`draft` / `submitted`,
 * Sprint 28); afterwards cancellation is admin-only.
 */
class CancelQuotationByCustomerAction
{
    public function __construct(private DashboardCacheInvalidatorInterface $dashboardCache) {}

    public function handle(CustomerProfile $profile, int $quotationId): Quotation
    {
        $quotation = DB::transaction(function () use ($profile, $quotationId): Quotation {
            $quotation = $profile
                ->quotations()
                ->whereKey($quotationId)
                ->lockForUpdate()
                ->first();

            if ($quotation === null) {
                throw new ModelNotFoundException;
            }

            if (! in_array($quotation->status, [
                QuotationStatus::Draft,
                QuotationStatus::Submitted,
            ], true)) {
                throw QuotationInvalidStateException::forAction(
                    'This quotation can no longer be cancelled by the customer.',
                );
            }

            $quotation->update(['status' => QuotationStatus::Cancelled]);

            $quotation->statusHistories()->create([
                'status' => QuotationStatus::Cancelled,
                'changed_by_type' => 'user',
                'changed_by_id' => $profile->user_id,
                'notes' => null,
            ]);

            DB::afterCommit(fn (): mixed => QuotationCancelled::dispatch($quotation));

            return $quotation->load(['booking', 'attachments.upload']);
        });

        $this->dashboardCache->invalidate();

        return $quotation;
    }
}
