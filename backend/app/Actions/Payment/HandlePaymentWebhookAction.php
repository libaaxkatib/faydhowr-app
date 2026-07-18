<?php

namespace App\Actions\Payment;

use App\Actions\Inventory\ProcessStoreOrderPaidStockAction;
use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\Payment\PaymentFailed;
use App\Events\Payment\PaymentPaid;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\StoreOrder;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Payments\PaymentNumberGenerator;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class HandlePaymentWebhookAction
{
    public function __construct(
        private PaymentGatewayManager $gatewayManager,
        private ProcessStoreOrderPaidStockAction $processStoreOrderPaidStock,
        private PaymentNumberGenerator $paymentNumbers,
        private DashboardCacheInvalidatorInterface $dashboardCache,
    ) {}

    /**
     * @param  array{gateway: string, transaction_reference: string, status: string}  $attributes
     */
    public function handle(array $attributes, ?string $signature, string $rawPayload): Payment
    {
        $gateway = $attributes['gateway'];

        if (! $this->gatewayManager->driver($gateway)->verifyWebhookSignature($rawPayload, $signature)) {
            throw new InvalidArgumentException('Invalid webhook signature.');
        }

        $payment = DB::transaction(function () use ($attributes): Payment {
            $transaction = PaymentTransaction::query()
                ->where('gateway', $attributes['gateway'])
                ->where('transaction_reference', $attributes['transaction_reference'])
                ->lockForUpdate()
                ->first();

            if ($transaction === null) {
                throw new ModelNotFoundException;
            }

            $payment = Payment::query()
                ->whereKey($transaction->payment_id)
                ->lockForUpdate()
                ->firstOrFail();

            $targetStatus = $this->targetStatus($attributes['status']);

            if ($this->isDuplicateCallback($payment, $targetStatus)) {
                return $payment->load(['payable', 'transactions', 'statusHistories']);
            }

            if ($payment->status->isTerminal()) {
                throw new DomainException('Payment is already in a terminal state.');
            }

            if ($payment->status !== PaymentStatus::Processing) {
                throw new DomainException('Only processing payments can be updated by webhook.');
            }

            if ($targetStatus === PaymentStatus::Paid) {
                $this->markPaid($payment, $transaction, $attributes);
            } else {
                $this->markFailed($payment, $transaction, $attributes);
            }

            return $payment->fresh(['payable', 'transactions', 'statusHistories']);
        });

        $this->dashboardCache->invalidate();

        return $payment;
    }

    private function targetStatus(string $status): PaymentStatus
    {
        return match ($status) {
            'success', 'paid' => PaymentStatus::Paid,
            'failed' => PaymentStatus::Failed,
            default => throw new DomainException('The webhook status is invalid.'),
        };
    }

    private function isDuplicateCallback(Payment $payment, PaymentStatus $targetStatus): bool
    {
        return $payment->status === $targetStatus;
    }

    /**
     * @param  array{gateway: string, transaction_reference: string, status: string}  $attributes
     */
    private function markPaid(Payment $payment, PaymentTransaction $transaction, array $attributes): void
    {
        $payment->update([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
            'receipt_number' => $payment->receipt_number ?? $this->paymentNumbers->nextReceiptNumber(),
        ]);

        $transaction->update([
            'status' => PaymentStatus::Paid->value,
            'response_payload' => $attributes,
            'processed_at' => now(),
        ]);

        $payment->statusHistories()->create([
            'status' => PaymentStatus::Paid,
            'changed_by_type' => 'system',
            'changed_by_id' => null,
            'notes' => null,
        ]);

        $this->confirmPayable($payment);

        DB::afterCommit(fn (): mixed => PaymentPaid::dispatch($payment));
    }

    /**
     * @param  array{gateway: string, transaction_reference: string, status: string}  $attributes
     */
    private function markFailed(Payment $payment, PaymentTransaction $transaction, array $attributes): void
    {
        $payment->update([
            'status' => PaymentStatus::Failed,
        ]);

        $transaction->update([
            'status' => PaymentStatus::Failed->value,
            'response_payload' => $attributes,
            'processed_at' => now(),
        ]);

        $payment->statusHistories()->create([
            'status' => PaymentStatus::Failed,
            'changed_by_type' => 'system',
            'changed_by_id' => null,
            'notes' => null,
        ]);

        DB::afterCommit(fn (): mixed => PaymentFailed::dispatch($payment));
    }

    private function confirmPayable(Payment $payment): void
    {
        if ($payment->payable_type === Order::class) {
            $this->confirmOrder($payment);

            return;
        }

        if ($payment->payable_type === StoreOrder::class) {
            $storeOrder = StoreOrder::query()
                ->whereKey($payment->payable_id)
                ->lockForUpdate()
                ->first();

            if ($storeOrder === null) {
                throw new DomainException('Store order not found for paid payment.');
            }

            $this->processStoreOrderPaidStock->handle($storeOrder);
        }
    }

    private function confirmOrder(Payment $payment): void
    {
        $order = Order::query()
            ->whereKey($payment->payable_id)
            ->lockForUpdate()
            ->first();

        if ($order === null || $order->status !== OrderStatus::PendingPayment) {
            return;
        }

        $order->update([
            'status' => OrderStatus::Confirmed,
        ]);

        $order->statusHistories()->create([
            'status' => OrderStatus::Confirmed,
            'changed_by_type' => 'system',
            'changed_by_id' => null,
            'notes' => null,
        ]);
    }
}
