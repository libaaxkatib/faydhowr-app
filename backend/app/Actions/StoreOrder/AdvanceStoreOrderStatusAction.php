<?php

namespace App\Actions\StoreOrder;

use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Enums\StoreOrderStatus;
use App\Models\Admin;
use App\Models\Payment;
use App\Models\StoreOrder;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Admin-driven store order fulfilment transitions (API Design §7.6A).
 * COD lifecycle: confirmed -> preparing -> out_for_delivery -> delivered ->
 * payment_pending; completion happens only through admin payment confirmation
 * (ConfirmOfflinePaymentAction). Prepaid orders keep the
 * confirmed -> processing -> completed path.
 */
class AdvanceStoreOrderStatusAction
{
    private const array ALLOWED_TRANSITIONS = [
        StoreOrderStatus::Confirmed->value => [StoreOrderStatus::Preparing, StoreOrderStatus::Processing],
        StoreOrderStatus::Preparing->value => [StoreOrderStatus::OutForDelivery],
        StoreOrderStatus::OutForDelivery->value => [StoreOrderStatus::Delivered],
        StoreOrderStatus::Delivered->value => [StoreOrderStatus::PaymentPending, StoreOrderStatus::Completed],
        StoreOrderStatus::Processing->value => [StoreOrderStatus::Completed],
    ];

    public function __construct(private DashboardCacheInvalidatorInterface $dashboardCache) {}

    public function handle(Admin $admin, int $storeOrderId, StoreOrderStatus $targetStatus): StoreOrder
    {
        $storeOrder = DB::transaction(function () use ($admin, $storeOrderId, $targetStatus): StoreOrder {
            $storeOrder = StoreOrder::query()
                ->whereKey($storeOrderId)
                ->lockForUpdate()
                ->first();

            if ($storeOrder === null) {
                throw new ModelNotFoundException;
            }

            $this->assertTransitionAllowed($storeOrder, $targetStatus);

            $storeOrder->update([
                'status' => $targetStatus,
            ]);

            $storeOrder->statusHistories()->create([
                'status' => $targetStatus,
                'changed_by_type' => 'admin',
                'changed_by_id' => $admin->id,
                'notes' => null,
            ]);

            return $storeOrder->load(['items', 'statusHistories']);
        });

        $this->dashboardCache->invalidate();

        return $storeOrder;
    }

    private function assertTransitionAllowed(StoreOrder $storeOrder, StoreOrderStatus $targetStatus): void
    {
        $allowed = self::ALLOWED_TRANSITIONS[$storeOrder->status->value] ?? [];

        if (! in_array($targetStatus, $allowed, true)) {
            throw new DomainException('This store order status transition is not allowed.');
        }

        if ($targetStatus === StoreOrderStatus::PaymentPending && ! $this->hasPendingPayment($storeOrder)) {
            throw new DomainException('Only orders awaiting payment collection can move to payment pending.');
        }

        // A COD order never completes before admin payment confirmation.
        if ($targetStatus === StoreOrderStatus::Completed && $this->hasPendingPayment($storeOrder)) {
            throw new DomainException('The order payment must be confirmed before the order can be completed.');
        }
    }

    private function hasPendingPayment(StoreOrder $storeOrder): bool
    {
        return Payment::query()
            ->where('payable_type', StoreOrder::class)
            ->where('payable_id', $storeOrder->id)
            ->active()
            ->exists();
    }
}
