<?php

namespace App\Actions\Quotation;

use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Enums\QuotationStatus;
use App\Models\CustomerProfile;
use App\Models\Quotation;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class AcceptQuotationAction
{
    public function __construct(private DashboardCacheInvalidatorInterface $dashboardCache) {}

    public function handle(CustomerProfile $profile, int $quotationId): Quotation
    {
        $quotation = DB::transaction(function () use ($profile, $quotationId): Quotation {
            $profile = CustomerProfile::query()
                ->whereKey($profile)
                ->lockForUpdate()
                ->firstOrFail();

            $quotation = $profile
                ->quotations()
                ->whereKey($quotationId)
                ->lockForUpdate()
                ->first();

            if ($quotation === null) {
                throw new ModelNotFoundException;
            }

            if (! in_array($quotation->status, [
                QuotationStatus::QuotationReady,
                QuotationStatus::UnderDiscussion,
            ], true)) {
                throw new DomainException('This quotation cannot be accepted.');
            }

            $quotation->status = QuotationStatus::Accepted;
            $quotation->accepted_at = now();
            $quotation->save();

            $quotation->statusHistories()->create([
                'status' => QuotationStatus::Accepted,
                'changed_by_type' => 'user',
                'changed_by_id' => $profile->user_id,
                'notes' => null,
            ]);

            return $quotation->load('booking');
        });

        $this->dashboardCache->invalidate();

        return $quotation;
    }
}
