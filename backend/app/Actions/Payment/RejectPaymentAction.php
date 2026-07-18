<?php

namespace App\Actions\Payment;

use App\Actions\Inventory\SaleReversalAction;
use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\StoreOrderStatus;
use App\Events\Audit\AuditEvent;
use App\Events\Payment\PaymentRejected;
use App\Models\Admin;
use App\Models\Payment;
use App\Models\StoreOrder;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Admin rejection of an offline payment (Sprint 27, API Design §18.9.1):
 * only active payments may be rejected, a reason is required, and a rejected
 * COD payment cancels the store order and restores stock (`sale_reversal`)
 * inside the same transaction.
 */
class RejectPaymentAction
{
    public function __construct(
        private SaleReversalAction $saleReversal,
        private DashboardCacheInvalidatorInterface $dashboardCache,
    ) {}

    public function handle(Admin $admin, int $paymentId, string $reason): Payment
    {
        $previousStatus = null;

        $payment = DB::transaction(function () use ($admin, $paymentId, $reason, &$previousStatus): Payment {
            $payment = Payment::query()
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                throw new ModelNotFoundException;
            }

            if (! $payment->status->isActive()) {
                throw new DomainException('Only active payments can be rejected.');
            }

            $previousStatus = $payment->status;

            $payment->update([
                'status' => PaymentStatus::Failed,
            ]);

            $payment->statusHistories()->create([
                'status' => PaymentStatus::Failed,
                'changed_by_type' => 'admin',
                'changed_by_id' => $admin->id,
                'notes' => $reason,
            ]);

            $this->cancelCashOnDeliveryOrder($payment, $admin, $reason);

            DB::afterCommit(fn (): mixed => PaymentRejected::dispatch($payment, $reason));

            return $payment->fresh(['payable', 'transactions', 'statusHistories']);
        });

        event(AuditEvent::record(
            action: AuditAction::PaymentReject,
            admin: $admin,
            description: 'Payment rejected.',
            entityType: Payment::class,
            entityId: $payment->id,
            metadata: [
                'previous_status' => $previousStatus?->value,
                'new_status' => PaymentStatus::Failed->value,
                'reason' => $reason,
            ],
        ));

        $this->dashboardCache->invalidate();

        return $payment;
    }

    /**
     * COD cascade (API Design §7.6A): payment FAILED -> store order CANCELLED
     * -> automatic inventory restock, all inside the same transaction.
     */
    private function cancelCashOnDeliveryOrder(Payment $payment, Admin $admin, string $reason): void
    {
        if ($payment->payment_method !== PaymentMethod::CashOnDelivery
            || $payment->payable_type !== StoreOrder::class) {
            return;
        }

        $storeOrder = StoreOrder::query()
            ->whereKey($payment->payable_id)
            ->lockForUpdate()
            ->first();

        if ($storeOrder === null) {
            throw new DomainException('Store order not found for rejected payment.');
        }

        if (in_array($storeOrder->status, [StoreOrderStatus::Completed, StoreOrderStatus::Cancelled], true)) {
            return;
        }

        $storeOrder->update([
            'status' => StoreOrderStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        $storeOrder->statusHistories()->create([
            'status' => StoreOrderStatus::Cancelled,
            'changed_by_type' => 'admin',
            'changed_by_id' => $admin->id,
            'notes' => $reason,
        ]);

        // COD stock is deducted at confirmation, so the cancellation restores it.
        $this->saleReversal->handle($storeOrder);
    }
}
