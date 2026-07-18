<?php

namespace App\Services\Booking;

use App\Contracts\Booking\Services\BookingPaymentGateInterface;
use App\Enums\PaymentStage;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Enums\ServicePaymentType;
use App\Models\Booking;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quotation;

/**
 * Booking payment gates (Sprint 26). Gates read the accepted quotation's
 * snapshotted payment policy, never the live service row.
 */
class BookingPaymentGateService implements BookingPaymentGateInterface
{
    public function isSchedulingPaymentConfirmed(Booking $booking): bool
    {
        $quotation = $this->acceptedQuotation($booking);

        if ($quotation === null) {
            return false;
        }

        $requiredStage = match ($quotation->payment_type ?? ServicePaymentType::FullBeforeService) {
            ServicePaymentType::FullBeforeService => PaymentStage::Full,
            ServicePaymentType::Deposit => PaymentStage::Deposit,
            ServicePaymentType::PayAfterService => null,
        };

        if ($requiredStage === null) {
            return true;
        }

        return $this->hasPaidStage($quotation, $requiredStage);
    }

    public function areAllRequiredPaymentsConfirmed(Booking $booking): bool
    {
        $quotation = $this->acceptedQuotation($booking);

        if ($quotation === null) {
            return false;
        }

        $requiredStages = match ($quotation->payment_type ?? ServicePaymentType::FullBeforeService) {
            ServicePaymentType::FullBeforeService => [PaymentStage::Full],
            ServicePaymentType::Deposit => [PaymentStage::Deposit, PaymentStage::Balance],
            ServicePaymentType::PayAfterService => [PaymentStage::Full],
        };

        foreach ($requiredStages as $stage) {
            if (! $this->hasPaidStage($quotation, $stage)) {
                return false;
            }
        }

        return true;
    }

    private function acceptedQuotation(Booking $booking): ?Quotation
    {
        return Quotation::query()
            ->where('booking_id', $booking->id)
            ->where('status', QuotationStatus::Accepted->value)
            ->latest('accepted_at')
            ->first();
    }

    private function hasPaidStage(Quotation $quotation, PaymentStage $stage): bool
    {
        return Payment::query()
            ->where('payable_type', Order::class)
            ->whereIn(
                'payable_id',
                Order::query()->where('quotation_id', $quotation->id)->select('id'),
            )
            ->where('payment_stage', $stage->value)
            ->where('status', PaymentStatus::Paid->value)
            ->exists();
    }
}
