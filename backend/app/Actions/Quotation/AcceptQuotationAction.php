<?php

namespace App\Actions\Quotation;

use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Enums\BookingStatus;
use App\Enums\QuotationStatus;
use App\Enums\ServicePaymentType;
use App\Exceptions\Quotation\QuotationInvalidStateException;
use App\Exceptions\Quotation\QuotationRevisionStaleException;
use App\Models\Admin;
use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\Quotation;
use App\Models\QuotationRevision;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class AcceptQuotationAction
{
    public function __construct(private DashboardCacheInvalidatorInterface $dashboardCache) {}

    public function handle(
        CustomerProfile $profile,
        int $quotationId,
        ?int $revisionId = null,
        ?int $versionNumber = null,
        ?Admin $actingAdmin = null,
    ): Quotation {
        $quotation = DB::transaction(function () use ($profile, $quotationId, $revisionId, $versionNumber, $actingAdmin): Quotation {
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
                throw QuotationInvalidStateException::forAction('This quotation cannot be accepted.');
            }

            $this->assertLatestRevisionReferenced($quotation, $revisionId, $versionNumber);

            $quotation->status = QuotationStatus::Accepted;
            $quotation->accepted_at = now();
            $this->applyPaymentPolicySnapshot($quotation);
            $quotation->save();

            $quotation->statusHistories()->create([
                'status' => QuotationStatus::Accepted,
                'changed_by_type' => $actingAdmin !== null ? 'admin' : 'user',
                'changed_by_id' => $actingAdmin?->id ?? $profile->user_id,
                'notes' => null,
            ]);

            $this->acceptLinkedBooking($quotation, $profile, $actingAdmin);

            return $quotation->load('booking');
        });

        $this->dashboardCache->invalidate();

        return $quotation;
    }

    /**
     * Latest-revision validation (Sprint 28): acceptance must reference the
     * latest immutable revision and fall within its validity window. Runs
     * inside the row-locked transaction, so a concurrent admin revision
     * cannot slip in between validation and acceptance. Quotations created
     * before the revision system (no revisions) keep the head validity rule.
     */
    private function assertLatestRevisionReferenced(
        Quotation $quotation,
        ?int $revisionId,
        ?int $versionNumber,
    ): void {
        if ($quotation->latest_revision_id === null) {
            if ($quotation->valid_until !== null && $quotation->valid_until->isPast()) {
                throw QuotationInvalidStateException::forAction('This quotation offer has expired and cannot be accepted.');
            }

            return;
        }

        $latestRevision = QuotationRevision::query()
            ->whereKey($quotation->latest_revision_id)
            ->firstOrFail();

        if ($revisionId === null && $versionNumber === null) {
            throw new DomainException('The revision reference is required to accept this quotation.');
        }

        if (($revisionId !== null && $revisionId !== $latestRevision->id)
            || ($versionNumber !== null && $versionNumber !== $latestRevision->version_number)) {
            throw QuotationRevisionStaleException::make();
        }

        if (! $latestRevision->isWithinValidity()) {
            throw QuotationInvalidStateException::forAction('This quotation offer has expired and cannot be accepted.');
        }
    }

    /**
     * Quotation acceptance automatically moves the linked booking to Accepted
     * (Sprint 27). There is no separate admin acceptance step.
     */
    private function acceptLinkedBooking(Quotation $quotation, CustomerProfile $profile, ?Admin $actingAdmin): void
    {
        if ($quotation->booking_id === null) {
            return;
        }

        $booking = Booking::query()
            ->whereKey($quotation->booking_id)
            ->lockForUpdate()
            ->first();

        if ($booking === null || ! in_array($booking->status, [
            BookingStatus::Submitted,
            BookingStatus::PendingReview,
            BookingStatus::QuotationReady,
            BookingStatus::UnderDiscussion,
        ], true)) {
            return;
        }

        $booking->update([
            'status' => BookingStatus::Accepted,
        ]);

        $booking->statusHistories()->create([
            'status' => BookingStatus::Accepted,
            'changed_by_type' => $actingAdmin !== null ? 'admin' : 'user',
            'changed_by_id' => $actingAdmin?->id ?? $profile->user_id,
            'notes' => null,
        ]);
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
