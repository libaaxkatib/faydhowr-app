<?php

namespace App\Actions\Cart;

use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CustomerProfile;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class UpdateCartItemAction
{
    public function __construct(private GetCartAction $getCart) {}

    public function handle(CustomerProfile $profile, int $itemId, int $quantity): Cart
    {
        return DB::transaction(function () use ($profile, $itemId, $quantity): Cart {
            $item = CartItem::query()
                ->whereKey($itemId)
                ->whereHas('cart', function ($query) use ($profile): void {
                    $query->where('customer_profile_id', $profile->id);
                })
                ->with('product')
                ->lockForUpdate()
                ->first();

            if ($item === null) {
                throw new ModelNotFoundException('Cart item not found.');
            }

            $product = $item->product;

            if ($product === null || $product->status !== ProductStatus::Active) {
                throw new DomainException('Only active products can remain in the cart.');
            }

            if ($quantity > $product->current_stock) {
                throw new DomainException('The requested quantity exceeds available stock.');
            }

            $item->update([
                'quantity' => $quantity,
            ]);

            return $this->getCart->handle($profile);
        });
    }
}
