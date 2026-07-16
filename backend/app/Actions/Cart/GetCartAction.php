<?php

namespace App\Actions\Cart;

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Models\CustomerProfile;
use Illuminate\Support\Facades\DB;

class GetCartAction
{
    public function handle(CustomerProfile $profile): Cart
    {
        return DB::transaction(function () use ($profile): Cart {
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

            return $cart->load([
                'items.product.images',
                'items.product.category',
            ]);
        });
    }
}
