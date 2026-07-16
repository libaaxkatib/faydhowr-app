<?php

namespace App\Actions\Order;

use App\Enums\OrderStatus;
use App\Enums\QuotationStatus;
use App\Models\CustomerProfile;
use App\Models\Order;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class CreateOrderAction
{
    public function handle(CustomerProfile $profile, int $quotationId): Order
    {
        return DB::transaction(function () use ($profile, $quotationId): Order {
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

            if ($quotation->status !== QuotationStatus::Accepted) {
                throw new DomainException('The quotation must be accepted before creating an order.');
            }

            $order = Order::query()->create([
                'order_number' => $this->nextOrderNumber(),
                'customer_profile_id' => $profile->id,
                'quotation_id' => $quotation->id,
                'status' => OrderStatus::PendingPayment,
                'currency' => $quotation->currency,
                'subtotal' => $quotation->subtotal,
                'discount_amount' => $quotation->discount_amount,
                'tax_amount' => $quotation->tax_amount,
                'total_amount' => $quotation->total_amount,
                'notes' => $quotation->notes,
            ]);

            $order->statusHistories()->create([
                'status' => OrderStatus::PendingPayment,
                'changed_by_type' => 'user',
                'changed_by_id' => $profile->user_id,
                'notes' => null,
            ]);

            return $order->load('quotation');
        });
    }

    private function nextOrderNumber(): string
    {
        $year = now()->format('Y');

        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["order-number-{$year}"]);
        }

        $latestOrderNumber = Order::withTrashed()
            ->where('order_number', 'like', "ORD-{$year}-%")
            ->orderByDesc('order_number')
            ->lockForUpdate()
            ->value('order_number');

        $nextSequence = $latestOrderNumber === null
            ? 1
            : ((int) substr($latestOrderNumber, -6)) + 1;

        if ($nextSequence > 999999) {
            throw new DomainException('The order number range for this year is exhausted.');
        }

        return sprintf('ORD-%s-%06d', $year, $nextSequence);
    }
}
