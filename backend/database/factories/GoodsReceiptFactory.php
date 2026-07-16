<?php

namespace Database\Factories;

use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoodsReceipt>
 */
class GoodsReceiptFactory extends Factory
{
    protected $model = GoodsReceipt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $supplier = Supplier::factory()->create();
        $purchaseOrder = PurchaseOrder::factory()->approved()->create([
            'supplier_id' => $supplier->id,
        ]);

        return [
            'gr_number' => sprintf(
                'GR-%s-%06d',
                now()->format('Y'),
                fake()->unique()->numberBetween(1, 999999),
            ),
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $purchaseOrder->id,
            'received_at' => now(),
            'notes' => null,
        ];
    }
}
