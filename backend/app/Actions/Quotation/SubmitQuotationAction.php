<?php

namespace App\Actions\Quotation;

use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Enums\QuotationStatus;
use App\Events\Quotation\QuotationSubmitted;
use App\Exceptions\Quotation\QuotationInvalidStateException;
use App\Models\CustomerProfile;
use App\Models\Quotation;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Submit (`draft` → `submitted`, Sprint 28): the request and its attachments
 * become permanently immutable from this moment; operations are notified
 * after commit.
 */
class SubmitQuotationAction
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

            if ($quotation->status !== QuotationStatus::Draft) {
                throw QuotationInvalidStateException::forAction('Only draft quotations can be submitted.');
            }

            if (trim((string) $quotation->requirements) === '') {
                throw new DomainException('The requirements must be completed before submitting.');
            }

            $quotation->update([
                'status' => QuotationStatus::Submitted,
                'submitted_at' => now(),
            ]);

            $quotation->statusHistories()->create([
                'status' => QuotationStatus::Submitted,
                'changed_by_type' => 'user',
                'changed_by_id' => $profile->user_id,
                'notes' => null,
            ]);

            DB::afterCommit(fn (): mixed => QuotationSubmitted::dispatch($quotation));

            return $quotation->load(['booking', 'attachments.upload']);
        });

        $this->dashboardCache->invalidate();

        return $quotation;
    }
}
