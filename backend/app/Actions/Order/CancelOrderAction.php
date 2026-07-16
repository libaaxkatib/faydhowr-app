<?php

namespace App\Actions\Order;

use App\Enums\OrderStatus;
use App\Models\CustomerProfile;
use App\Models\Order;
use DomainException;
use Illuminate\Support\Facades\DB;

class CancelOrderAction
{
    public function handle(
        CustomerProfile $profile,
        int $orderId,
        ?string $cancellationReason,
    ): ?Order {
        return DB::transaction(function () use ($profile, $orderId, $cancellationReason): ?Order {
            $profile = CustomerProfile::query()
                ->whereKey($profile)
                ->lockForUpdate()
                ->firstOrFail();

            $order = $profile
                ->orders()
                ->whereKey($orderId)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                return null;
            }

            if ($order->status !== OrderStatus::PendingPayment) {
                throw new DomainException('This order cannot be cancelled.');
            }

            $order->status = OrderStatus::Cancelled;
            $order->cancelled_at = now();
            $order->cancellation_reason = $cancellationReason;
            $order->save();

            $order->statusHistories()->create([
                'status' => OrderStatus::Cancelled,
                'changed_by_type' => 'user',
                'changed_by_id' => $profile->user_id,
                'notes' => $cancellationReason,
            ]);

            return $order->load('quotation');
        });
    }
}
