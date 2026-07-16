<?php

namespace Database\Factories;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Product;
use App\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoodsReceiptItem>
 */
class GoodsReceiptItemFactory extends Factory
{
    protected $model = GoodsReceiptItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $product = Product::factory()->create();

        return [
            'goods_receipt_id' => GoodsReceipt::factory(),
            'purchase_order_item_id' => null,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'product_name' => $product->name,
            'quantity_received' => fake()->numberBetween(1, 20),
            'unit_cost' => fake()->randomFloat(2, 1, 100),
        ];
    }

    public function forPurchaseOrderItem(PurchaseOrderItem $item): static
    {
        return $this->state(fn (): array => [
            'purchase_order_item_id' => $item->id,
            'product_id' => $item->product_id,
            'sku' => $item->sku,
            'product_name' => $item->product_name,
            'unit_cost' => $item->unit_cost,
        ]);
    }
}
