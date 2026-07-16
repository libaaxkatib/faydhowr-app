<?php

namespace Database\Factories;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockLedger;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockLedger>
 */
class StockLedgerFactory extends Factory
{
    protected $model = StockLedger::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'movement_type' => StockMovementType::Adjustment,
            'quantity' => fake()->numberBetween(1, 10),
            'reference_type' => null,
            'reference_id' => null,
            'created_at' => now(),
        ];
    }
}
