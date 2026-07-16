<?php

namespace App\Actions\Checkout;

use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use DomainException;
use Illuminate\Support\Facades\DB;

class CheckoutAction
{
    /**
     * @return array{
     *     items: list<array{
     *         sku: string,
     *         name: string,
     *         slug: string,
     *         quantity: int,
     *         unit_price: string,
     *         line_total: string,
     *         currency: string
     *     }>,
     *     totals: array{
     *         total_items: int,
     *         total_quantity: int,
     *         subtotal: string
     *     },
     *     address: CustomerAddress
     * }
     */
    public function handle(CustomerProfile $profile, int $addressId): array
    {
        return DB::transaction(function () use ($profile, $addressId): array {
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

            $items = $cart->items()
                ->with(['product' => fn ($query) => $query->lockForUpdate()])
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                throw new DomainException('CART_EMPTY');
            }

            $snapshotItems = [];
            $totalQuantity = 0;
            $subtotal = '0.00';

            foreach ($items as $item) {
                $product = $item->product;

                if ($product === null || $product->trashed()) {
                    throw new DomainException('PRODUCT_INACTIVE');
                }

                if ($product->status !== ProductStatus::Active) {
                    throw new DomainException('PRODUCT_INACTIVE');
                }

                if ($product->current_stock <= 0 || $item->quantity > $product->current_stock) {
                    throw new DomainException('INSUFFICIENT_STOCK');
                }

                $unitPrice = (string) $product->selling_price;
                $lineTotal = bcmul($unitPrice, (string) $item->quantity, 2);

                $snapshotItems[] = [
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'quantity' => $item->quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'currency' => $product->currency,
                ];

                $totalQuantity += $item->quantity;
                $subtotal = bcadd($subtotal, $lineTotal, 2);
            }

            return [
                'items' => $snapshotItems,
                'totals' => [
                    'total_items' => count($snapshotItems),
                    'total_quantity' => $totalQuantity,
                    'subtotal' => $subtotal,
                ],
                'address' => $address,
            ];
        });
    }
}
