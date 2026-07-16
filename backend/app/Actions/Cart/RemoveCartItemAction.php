<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CustomerProfile;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class RemoveCartItemAction
{
    public function __construct(private GetCartAction $getCart) {}

    public function handle(CustomerProfile $profile, int $itemId): Cart
    {
        return DB::transaction(function () use ($profile, $itemId): Cart {
            $item = CartItem::query()
                ->whereKey($itemId)
                ->whereHas('cart', function ($query) use ($profile): void {
                    $query->where('customer_profile_id', $profile->id);
                })
                ->lockForUpdate()
                ->first();

            if ($item === null) {
                throw new ModelNotFoundException('Cart item not found.');
            }

            $item->delete();

            return $this->getCart->handle($profile);
        });
    }
}
