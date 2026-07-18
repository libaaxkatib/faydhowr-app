<?php

namespace App\Actions\Payment;

use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\DataTransferObjects\Payment\InitializePaymentData;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStage;
use App\Enums\PaymentStatus;
use App\Enums\ServicePaymentType;
use App\Enums\StoreOrderStatus;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreOrder;
use App\Support\Payments\PaymentNumberGenerator;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class InitializePaymentAction
{
    public function __construct(
        private PaymentNumberGenerator $paymentNumbers,
        private DashboardCacheInvalidatorInterface $dashboardCache,
    ) {}

    public function handle(CustomerProfile $profile, InitializePaymentData $data): Payment
    {
        $payment = DB::transaction(function () use ($profile, $data): Payment {
            $profile = CustomerProfile::query()
                ->whereKey($profile)
                ->lockForUpdate()
                ->firstOrFail();

            $replayedPayment = $this->findByIdempotencyKey($profile, $data->idempotencyKey);

            if ($replayedPayment !== null) {
                return $replayedPayment->load(['payable', 'transactions', 'statusHistories']);
            }

            $payable = $this->resolvePayable($profile, $data);

            $existingPayment = Payment::query()
                ->where('payable_type', $payable::class)
                ->where('payable_id', $payable->id)
                ->active()
                ->lockForUpdate()
                ->first();

            if ($existingPayment !== null) {
                return $existingPayment->load(['payable', 'transactions', 'statusHistories']);
            }

            $amount = $payable instanceof StoreOrder
                ? $this->storeOrderInstallment($payable, $data)
                : $this->orderInstallment($payable, $data);

            // V1 ships without online gateways: offline payments stay pending
            // until an admin confirms receipt/collection (API Design §11.2).
            $payment = Payment::query()->create([
                'payment_number' => $this->paymentNumbers->nextPaymentNumber(),
                'customer_profile_id' => $profile->id,
                'payable_type' => $payable::class,
                'payable_id' => $payable->id,
                'status' => PaymentStatus::Pending,
                'payment_method' => $data->paymentMethod,
                'payment_stage' => $data->paymentStage,
                'idempotency_key' => $data->idempotencyKey,
                'amount' => $amount,
                'currency' => $payable->currency,
            ]);

            $payment->statusHistories()->create([
                'status' => PaymentStatus::Pending,
                'changed_by_type' => 'user',
                'changed_by_id' => $profile->user_id,
                'notes' => null,
            ]);

            return $payment->load(['payable', 'transactions', 'statusHistories']);
        });

        $this->dashboardCache->invalidate();

        return $payment;
    }

    private function findByIdempotencyKey(CustomerProfile $profile, string $idempotencyKey): ?Payment
    {
        $payment = Payment::query()
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($payment === null) {
            return null;
        }

        if ($payment->customer_profile_id !== $profile->id) {
            throw new DomainException('The idempotency key has already been used.');
        }

        return $payment;
    }

    private function resolvePayable(CustomerProfile $profile, InitializePaymentData $data): Order|StoreOrder
    {
        if ($data->payableType === 'store_order') {
            $storeOrder = $profile->storeOrders()
                ->whereKey($data->payableId)
                ->lockForUpdate()
                ->first();

            if ($storeOrder === null) {
                throw new ModelNotFoundException;
            }

            return $storeOrder;
        }

        $order = $profile->orders()
            ->whereKey($data->payableId)
            ->lockForUpdate()
            ->first();

        if ($order === null) {
            throw new ModelNotFoundException;
        }

        return $order;
    }

    private function storeOrderInstallment(StoreOrder $storeOrder, InitializePaymentData $data): string
    {
        if ($data->paymentMethod === PaymentMethod::CashOnService) {
            throw new DomainException('Cash on service is available for cleaning services only.');
        }

        if ($data->paymentMethod === PaymentMethod::CashOnDelivery) {
            throw new DomainException('Cash on delivery payments are created with the store order.');
        }

        if ($data->paymentStage !== PaymentStage::Full) {
            throw new DomainException('Store order payments support the full payment stage only.');
        }

        if ($storeOrder->status !== StoreOrderStatus::PendingPayment) {
            throw new DomainException('The store order must be pending payment before initializing a payment.');
        }

        return (string) $storeOrder->subtotal;
    }

    /**
     * Server-calculated installment for the payable's snapshotted payment
     * policy (API Design §11.1): `full` = full payable total; `deposit` =
     * quotation deposit snapshot; `balance` = quotation remaining snapshot.
     */
    private function orderInstallment(Order $order, InitializePaymentData $data): string
    {
        if ($data->paymentMethod === PaymentMethod::CashOnDelivery) {
            throw new DomainException('Cash on delivery is available for store orders only.');
        }

        $quotation = $order->quotation;
        $paymentType = $quotation?->payment_type ?? ServicePaymentType::FullBeforeService;

        return match ($paymentType) {
            ServicePaymentType::FullBeforeService => $this->fullInstallment($order, $data),
            ServicePaymentType::Deposit => $this->depositPolicyInstallment($order, $data),
            ServicePaymentType::PayAfterService => $this->payAfterServiceInstallment($order, $data),
        };
    }

    private function fullInstallment(Order $order, InitializePaymentData $data): string
    {
        if ($data->paymentStage !== PaymentStage::Full) {
            throw new DomainException('This order requires full payment before service.');
        }

        $this->assertOrderPendingPayment($order);

        return (string) $order->total_amount;
    }

    private function depositPolicyInstallment(Order $order, InitializePaymentData $data): string
    {
        $quotation = $order->quotation;

        if ($data->paymentStage === PaymentStage::Deposit) {
            $this->assertOrderPendingPayment($order);

            return (string) $quotation->deposit_amount;
        }

        if ($data->paymentStage === PaymentStage::Balance) {
            if (! $this->hasPaidStage($order, PaymentStage::Deposit)) {
                throw new DomainException('The deposit payment must be confirmed before the remaining balance.');
            }

            $this->assertServiceCompleted($order);

            return (string) $quotation->remaining_amount;
        }

        throw new DomainException('This order uses the deposit then balance payment sequence.');
    }

    private function payAfterServiceInstallment(Order $order, InitializePaymentData $data): string
    {
        if ($data->paymentStage !== PaymentStage::Full) {
            throw new DomainException('This order is payable in full after service completion.');
        }

        $this->assertOrderPendingPayment($order);
        $this->assertServiceCompleted($order);

        return (string) $order->total_amount;
    }

    private function assertOrderPendingPayment(Order $order): void
    {
        if ($order->status !== OrderStatus::PendingPayment) {
            throw new DomainException('The order must be pending payment before initializing a payment.');
        }
    }

    private function assertServiceCompleted(Order $order): void
    {
        $booking = $order->quotation?->booking;

        if ($booking === null || $booking->status !== BookingStatus::Completed) {
            throw new DomainException('This payment becomes payable after service completion.');
        }
    }

    private function hasPaidStage(Order $order, PaymentStage $stage): bool
    {
        return Payment::query()
            ->where('payable_type', Order::class)
            ->where('payable_id', $order->id)
            ->where('payment_stage', $stage->value)
            ->where('status', PaymentStatus::Paid->value)
            ->exists();
    }
}
