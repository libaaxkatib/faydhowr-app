<?php

namespace App\Actions\Cart;

use App\Enums\CartStatus;
use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CustomerProfile;
use App\Models\Product;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class AddToCartAction
{
    public function __construct(private GetCartAction $getCart) {}

    public function handle(CustomerProfile $profile, int $productId, int $quantity): Cart
    {
        return DB::transaction(function () use ($profile, $productId, $quantity): Cart {
            $cart = Cart::query()
                ->where('customer_profile_id', $profile->id)
                ->lockForUpdate()
                ->first();

            if ($cart === null) {
                $cart = Cart::query()->create([
                    'customer_profile_id' => $profile->id,
                    'status' => CartStatus::Active,
                ]);
            }

            $product = Product::query()
                ->whereKey($productId)
                ->lockForUpdate()
                ->first();

            if ($product === null) {
                throw new ModelNotFoundException('Product not found.');
            }

            if ($product->status !== ProductStatus::Active) {
                throw new DomainException('Only active products can be added to the cart.');
            }

            if ($product->current_stock <= 0) {
                throw new DomainException('The selected product is out of stock.');
            }

            $existingItem = CartItem::query()
                ->where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();

            $newQuantity = $existingItem === null
                ? $quantity
                : $existingItem->quantity + $quantity;

            if ($newQuantity > $product->current_stock) {
                throw new DomainException('The requested quantity exceeds available stock.');
            }

            if ($existingItem === null) {
                CartItem::query()->create([
                    'cart_id' => $cart->id,
                    'product_id' => $product->id,
                    'quantity' => $newQuantity,
                ]);
            } else {
                $existingItem->update([
                    'quantity' => $newQuantity,
                ]);
            }

            return $this->getCart->handle($profile);
        });
    }
}
