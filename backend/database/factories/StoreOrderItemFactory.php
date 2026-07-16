<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoreOrderItem>
 */
class StoreOrderItemFactory extends Factory
{
    protected $model = StoreOrderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 5);
        $unitPrice = fake()->randomFloat(2, 1, 50);

        return [
            'store_order_id' => StoreOrder::factory(),
            'product_id' => Product::factory(),
            'sku_snapshot' => strtoupper(fake()->unique()->bothify('SKU-####??')),
            'product_name_snapshot' => fake()->words(3, true),
            'quantity' => $quantity,
            'unit_price_snapshot' => $unitPrice,
            'line_total_snapshot' => round($quantity * $unitPrice, 2),
        ];
    }
}
