<?php

namespace App\Actions\Quotation;

use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Enums\QuotationStatus;
use App\Enums\ServicePaymentType;
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
            $this->applyPaymentPolicySnapshot($quotation);
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

    /**
     * Snapshot the service payment policy at acceptance (Sprint 26). Later
     * changes to the service's payment policy never affect this quotation;
     * payment gates read the snapshot, never the live service row.
     */
    private function applyPaymentPolicySnapshot(Quotation $quotation): void
    {
        $service = $quotation->booking?->service;
        $paymentType = $service?->payment_type ?? ServicePaymentType::FullBeforeService;
        $total = (string) $quotation->total_amount;

        $quotation->payment_type = $paymentType;

        if ($paymentType === ServicePaymentType::Deposit) {
            $depositPercentage = (int) $service?->deposit_percentage;
            $depositAmount = bcdiv(bcmul($total, (string) $depositPercentage, 2), '100', 2);

            $quotation->deposit_percentage = $depositPercentage;
            $quotation->deposit_amount = $depositAmount;
            $quotation->remaining_amount = bcsub($total, $depositAmount, 2);

            return;
        }

        $quotation->deposit_percentage = null;
        $quotation->deposit_amount = null;
        $quotation->remaining_amount = $paymentType === ServicePaymentType::PayAfterService
            ? $total
            : null;
    }
}
