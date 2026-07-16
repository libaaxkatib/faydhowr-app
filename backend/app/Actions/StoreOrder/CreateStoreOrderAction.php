<?php

namespace App\Actions\StoreOrder;

use App\Enums\ProductStatus;
use App\Enums\StoreOrderStatus;
use App\Models\Cart;
use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use App\Models\StoreOrder;
use DomainException;
use Illuminate\Support\Facades\DB;

class CreateStoreOrderAction
{
    public function handle(CustomerProfile $profile, int $addressId, ?string $notes = null): StoreOrder
    {
        return DB::transaction(function () use ($profile, $addressId, $notes): StoreOrder {
            $profile = CustomerProfile::query()
                ->whereKey($profile)
                ->lockForUpdate()
                ->firstOrFail();

            $address = CustomerAddress::query()
                ->whereKey($addressId)
                ->where('customer_profile_id', $profile->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($address === null) {
                throw new DomainException('ADDRESS_NOT_FOUND');
            }

            $cart = Cart::query()
                ->where('customer_profile_id', $profile->id)
                ->lockForUpdate()
                ->first();

            if ($cart === null) {
                throw new DomainException('CART_EMPTY');
            }

            $cartItems = $cart->items()
                ->with(['product' => fn ($query) => $query->lockForUpdate()])
                ->lockForUpdate()
                ->get();

            if ($cartItems->isEmpty()) {
                throw new DomainException('CART_EMPTY');
            }

            $lineSnapshots = [];
            $totalQuantity = 0;
            $subtotal = '0.00';
            $currency = null;

            foreach ($cartItems as $cartItem) {
                $product = $cartItem->product;

                if ($product === null || $product->trashed() || $product->status !== ProductStatus::Active) {
                    throw new DomainException('PRODUCT_INACTIVE');
                }

                if ($product->current_stock <= 0 || $cartItem->quantity > $product->current_stock) {
                    throw new DomainException('INSUFFICIENT_STOCK');
                }

                $unitPrice = (string) $product->selling_price;
                $lineTotal = bcmul($unitPrice, (string) $cartItem->quantity, 2);
                $currency ??= $product->currency;

                if ($currency !== $product->currency) {
                    throw new DomainException('Cart items must share the same currency.');
                }

                $lineSnapshots[] = [
                    'product_id' => $product->id,
                    'sku_snapshot' => $product->sku,
                    'product_name_snapshot' => $product->name,
                    'quantity' => $cartItem->quantity,
                    'unit_price_snapshot' => $unitPrice,
                    'line_total_snapshot' => $lineTotal,
                ];

                $totalQuantity += $cartItem->quantity;
                $subtotal = bcadd($subtotal, $lineTotal, 2);
            }

            $storeOrder = StoreOrder::query()->create([
                'store_order_number' => $this->nextStoreOrderNumber(),
                'customer_profile_id' => $profile->id,
                'cart_id' => $cart->id,
                'customer_address_id' => $address->id,
                'status' => StoreOrderStatus::PendingPayment,
                'currency' => $currency,
                'total_items' => count($lineSnapshots),
                'total_quantity' => $totalQuantity,
                'subtotal' => $subtotal,
                'shipping_address_snapshot' => [
                    'label' => $address->label,
                    'contact_name' => $address->contact_name,
                    'phone' => $address->phone,
                    'line1' => $address->line1,
                    'line2' => $address->line2,
                    'city' => $address->city,
                    'state_region' => $address->state_region,
                    'postal_code' => $address->postal_code,
                    'country_code' => $address->country_code,
                ],
                'notes' => $notes,
            ]);

            foreach ($lineSnapshots as $lineSnapshot) {
                $storeOrder->items()->create($lineSnapshot);
            }

            $storeOrder->statusHistories()->create([
                'status' => StoreOrderStatus::PendingPayment,
                'changed_by_type' => 'user',
                'changed_by_id' => $profile->user_id,
                'notes' => null,
            ]);

            $cart->items()->delete();

            return $storeOrder->load(['items', 'statusHistories']);
        });
    }

    private function nextStoreOrderNumber(): string
    {
        $year = now()->format('Y');

        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["store-order-number-{$year}"]);
        }

        $latestStoreOrderNumber = StoreOrder::withTrashed()
            ->where('store_order_number', 'like', "STO-{$year}-%")
            ->orderByDesc('store_order_number')
            ->lockForUpdate()
            ->value('store_order_number');

        $nextSequence = $latestStoreOrderNumber === null
            ? 1
            : ((int) substr($latestStoreOrderNumber, -6)) + 1;

        if ($nextSequence > 999999) {
            throw new DomainException('The store order number range for this year is exhausted.');
        }

        return sprintf('STO-%s-%06d', $year, $nextSequence);
    }
}
