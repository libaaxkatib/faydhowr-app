<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\CustomerProfile;
use Illuminate\Support\Facades\DB;

class ClearCartAction
{
    public function __construct(private GetCartAction $getCart) {}

    public function handle(CustomerProfile $profile): Cart
    {
        return DB::transaction(function () use ($profile): Cart {
            $cart = Cart::query()
                ->where('customer_profile_id', $profile->id)
                ->lockForUpdate()
                ->first();

            if ($cart !== null) {
                $cart->items()->delete();
            }

            return $this->getCart->handle($profile);
        });
    }
}
