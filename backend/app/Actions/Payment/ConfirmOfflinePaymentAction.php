<?php

namespace App\Actions\Payment;

use App\Actions\Inventory\ProcessStoreOrderPaidStockAction;
use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStage;
use App\Enums\PaymentStatus;
use App\Enums\ServicePaymentType;
use App\Enums\StoreOrderStatus;
use App\Events\Payment\PaymentPaid;
use App\Models\Admin;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreOrder;
use App\Support\Payments\PaymentNumberGenerator;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Admin-verified confirmation for V1 offline payment methods (API Design
 * §11.2): moves the payment `pending` -> `paid` with a full audit trail and
 * applies the payable side effects (store order stock/lifecycle, service
 * order confirmation, and booking payment gates).
 */
class ConfirmOfflinePaymentAction
{
    public function __construct(
        private PaymentNumberGenerator $paymentNumbers,
        private ProcessStoreOrderPaidStockAction $processStoreOrderPaidStock,
        private DashboardCacheInvalidatorInterface $dashboardCache,
    ) {}

    public function handle(Admin $admin, int $paymentId): Payment
    {
        $payment = DB::transaction(function () use ($admin, $paymentId): Payment {
            $payment = Payment::query()
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                throw new ModelNotFoundException;
            }

            if ($payment->status === PaymentStatus::Paid) {
                return $payment->load(['payable', 'transactions', 'statusHistories']);
            }

            if ($payment->status->isTerminal()) {
                throw new DomainException('Payment is already in a terminal state.');
            }

            $payment->update([
                'status' => PaymentStatus::Paid,
                'paid_at' => now(),
                'receipt_number' => $payment->receipt_number ?? $this->paymentNumbers->nextReceiptNumber(),
            ]);

            $payment->statusHistories()->create([
                'status' => PaymentStatus::Paid,
                'changed_by_type' => 'admin',
                'changed_by_id' => $admin->id,
                'notes' => null,
            ]);

            $this->confirmPayable($payment, $admin);

            DB::afterCommit(fn (): mixed => PaymentPaid::dispatch($payment));

            return $payment->fresh(['payable', 'transactions', 'statusHistories']);
        });

        $this->dashboardCache->invalidate();

        return $payment;
    }

    private function confirmPayable(Payment $payment, Admin $admin): void
    {
        if ($payment->payable_type === StoreOrder::class) {
            $this->confirmStoreOrderPayment($payment, $admin);

            return;
        }

        if ($payment->payable_type === Order::class) {
            $this->confirmOrderPayment($payment, $admin);
        }
    }

    private function confirmStoreOrderPayment(Payment $payment, Admin $admin): void
    {
        $storeOrder = StoreOrder::query()
            ->whereKey($payment->payable_id)
            ->lockForUpdate()
            ->first();

        if ($storeOrder === null) {
            throw new DomainException('Store order not found for paid payment.');
        }

        if ($payment->payment_method === PaymentMethod::CashOnDelivery) {
            if ($storeOrder->status !== StoreOrderStatus::PaymentPending) {
                throw new DomainException('Cash collection can be confirmed only while the store order awaits payment.');
            }

            $storeOrder->update([
                'status' => StoreOrderStatus::Completed,
            ]);

            $storeOrder->statusHistories()->create([
                'status' => StoreOrderStatus::Completed,
                'changed_by_type' => 'admin',
                'changed_by_id' => $admin->id,
                'notes' => null,
            ]);

            return;
        }

        // Prepaid methods: stock decreases only after the payment is paid.
        $this->processStoreOrderPaidStock->handle($storeOrder);
    }

    private function confirmOrderPayment(Payment $payment, Admin $admin): void
    {
        $order = Order::query()
            ->with('quotation.booking')
            ->whereKey($payment->payable_id)
            ->lockForUpdate()
            ->first();

        if ($order === null) {
            throw new DomainException('Order not found for paid payment.');
        }

        if ($order->status === OrderStatus::PendingPayment) {
            $order->update([
                'status' => OrderStatus::Confirmed,
            ]);

            $order->statusHistories()->create([
                'status' => OrderStatus::Confirmed,
                'changed_by_type' => 'admin',
                'changed_by_id' => $admin->id,
                'notes' => null,
            ]);
        }

        if ($this->isFinalServicePayment($payment, $order)) {
            $this->closeCompletedBooking($order, $admin);
        }
    }

    /**
     * The final payment is `balance` for the deposit policy and `full` for
     * pay-after-service; confirming it moves the booking completed -> closed.
     */
    private function isFinalServicePayment(Payment $payment, Order $order): bool
    {
        $paymentType = $order->quotation?->payment_type;

        if ($paymentType === ServicePaymentType::Deposit) {
            return $payment->payment_stage === PaymentStage::Balance;
        }

        if ($paymentType === ServicePaymentType::PayAfterService) {
            return $payment->payment_stage === PaymentStage::Full;
        }

        return false;
    }

    private function closeCompletedBooking(Order $order, Admin $admin): void
    {
        $booking = $order->quotation?->booking;

        if ($booking === null || $booking->status !== BookingStatus::Completed) {
            return;
        }

        $booking->update([
            'status' => BookingStatus::Closed,
        ]);

        $booking->statusHistories()->create([
            'status' => BookingStatus::Closed,
            'changed_by_type' => 'admin',
            'changed_by_id' => $admin->id,
            'notes' => null,
        ]);
    }
}
