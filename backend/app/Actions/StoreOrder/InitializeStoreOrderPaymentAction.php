<?php

namespace App\Actions\StoreOrder;

use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Enums\PaymentStatus;
use App\Enums\StoreOrderStatus;
use App\Models\CustomerProfile;
use App\Models\Payment;
use App\Models\StoreOrder;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class InitializeStoreOrderPaymentAction
{
    public function __construct(private DashboardCacheInvalidatorInterface $dashboardCache) {}

    /**
     * @param  array{store_order_id: int, gateway: string, gateway_reference?: string|null}  $attributes
     */
    public function handle(CustomerProfile $profile, array $attributes): Payment
    {
        $payment = DB::transaction(function () use ($profile, $attributes): Payment {
            $profile = CustomerProfile::query()
                ->whereKey($profile)
                ->lockForUpdate()
                ->firstOrFail();

            $storeOrder = $profile->storeOrders()
                ->whereKey($attributes['store_order_id'])
                ->lockForUpdate()
                ->first();

            if ($storeOrder === null) {
                throw new ModelNotFoundException;
            }

            if ($storeOrder->status !== StoreOrderStatus::PendingPayment) {
                throw new DomainException('The store order must be pending payment before initializing a payment.');
            }

            $existingPayment = Payment::query()
                ->where('payable_type', StoreOrder::class)
                ->where('payable_id', $storeOrder->id)
                ->active()
                ->lockForUpdate()
                ->first();

            if ($existingPayment !== null) {
                return $existingPayment->load(['payable', 'transactions', 'statusHistories']);
            }

            $payment = Payment::query()->create([
                'payment_number' => $this->nextPaymentNumber(),
                'customer_profile_id' => $profile->id,
                'payable_type' => StoreOrder::class,
                'payable_id' => $storeOrder->id,
                'status' => PaymentStatus::Initialized,
                'amount' => $storeOrder->subtotal,
                'currency' => $storeOrder->currency,
                'gateway' => $attributes['gateway'],
                'gateway_reference' => $attributes['gateway_reference'] ?? null,
            ]);

            $payment->transactions()->create([
                'gateway' => $attributes['gateway'],
                'transaction_reference' => $attributes['gateway_reference'] ?? null,
                'status' => PaymentStatus::Initialized->value,
            ]);

            $payment->statusHistories()->create([
                'status' => PaymentStatus::Initialized,
                'changed_by_type' => 'user',
                'changed_by_id' => $profile->user_id,
                'notes' => null,
            ]);

            return $payment->load(['payable', 'transactions', 'statusHistories']);
        });

        $this->dashboardCache->invalidate();

        return $payment;
    }

    private function nextPaymentNumber(): string
    {
        $year = now()->format('Y');

        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["payment-number-{$year}"]);
        }

        $latestPaymentNumber = Payment::withTrashed()
            ->where('payment_number', 'like', "PAY-{$year}-%")
            ->orderByDesc('payment_number')
            ->lockForUpdate()
            ->value('payment_number');

        $nextSequence = $latestPaymentNumber === null
            ? 1
            : ((int) substr($latestPaymentNumber, -6)) + 1;

        if ($nextSequence > 999999) {
            throw new DomainException('The payment number range for this year is exhausted.');
        }

        return sprintf('PAY-%s-%06d', $year, $nextSequence);
    }
}
