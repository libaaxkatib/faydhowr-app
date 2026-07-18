<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\SaleReversalAction;
use App\Enums\ProductStatus;
use App\Enums\StockMovementType;
use App\Enums\StoreOrderStatus;
use App\Models\Product;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SaleReversalActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_restores_quantities_and_writes_sale_reversal_ledger_entries(): void
    {
        $firstProduct = Product::factory()->create([
            'current_stock' => 7,
            'status' => ProductStatus::Active,
        ]);
        $secondProduct = Product::factory()->create([
            'current_stock' => 0,
            'status' => ProductStatus::Active,
        ]);

        $storeOrder = StoreOrder::factory()->create(['status' => StoreOrderStatus::Cancelled]);
        StoreOrderItem::factory()->create([
            'store_order_id' => $storeOrder->id,
            'product_id' => $firstProduct->id,
            'quantity' => 3,
        ]);
        StoreOrderItem::factory()->create([
            'store_order_id' => $storeOrder->id,
            'product_id' => $secondProduct->id,
            'quantity' => 2,
        ]);

        DB::transaction(fn () => app(SaleReversalAction::class)->handle($storeOrder));

        $this->assertDatabaseHas('products', [
            'id' => $firstProduct->id,
            'current_stock' => 10,
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $secondProduct->id,
            'current_stock' => 2,
        ]);
        $this->assertDatabaseHas('stock_ledgers', [
            'product_id' => $firstProduct->id,
            'movement_type' => StockMovementType::SaleReversal->value,
            'quantity' => 3,
            'reference_type' => StoreOrder::class,
            'reference_id' => $storeOrder->id,
        ]);
        $this->assertDatabaseHas('stock_ledgers', [
            'product_id' => $secondProduct->id,
            'movement_type' => StockMovementType::SaleReversal->value,
            'quantity' => 2,
            'reference_type' => StoreOrder::class,
            'reference_id' => $storeOrder->id,
        ]);
    }

    public function test_it_rejects_a_store_order_without_items(): void
    {
        $storeOrder = StoreOrder::factory()->create(['status' => StoreOrderStatus::Cancelled]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Store order has no items to restore to stock.');

        DB::transaction(fn () => app(SaleReversalAction::class)->handle($storeOrder));
    }
}
