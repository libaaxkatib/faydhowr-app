<?php

namespace Tests\Feature\Api\V1\PurchaseOrder;

use App\Enums\PurchaseOrderStatus;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_approve_a_submitted_purchase_order(): void
    {
        $user = User::factory()->create();
        $purchaseOrder = $this->createPurchaseOrder(PurchaseOrderStatus::Submitted);

        $response = $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->patchJson('/api/v1/purchase-orders/'.$purchaseOrder->id.'/approve');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Purchase order approved successfully.')
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $purchaseOrder->id,
            'status' => PurchaseOrderStatus::Approved->value,
        ]);
        $this->assertNotNull($purchaseOrder->fresh()->approved_at);
        $this->assertDatabaseHas('purchase_order_status_histories', [
            'purchase_order_id' => $purchaseOrder->id,
            'status' => PurchaseOrderStatus::Approved->value,
            'changed_by_type' => 'admin',
        ]);
    }

    public function test_goods_receipt_is_blocked_for_submitted_purchase_orders(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('admin-panel')->plainTextToken;
        $purchaseOrder = $this->createPurchaseOrder(PurchaseOrderStatus::Submitted);
        $item = $purchaseOrder->items()->first();

        $this->withToken($token)
            ->postJson('/api/v1/goods-receipts', [
                'purchase_order_id' => $purchaseOrder->id,
                'items' => [[
                    'purchase_order_item_id' => $item->id,
                    'quantity_received' => 1,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath(
                'message',
                'Goods receipts can only be created for approved or partially received purchase orders.',
            );

        $this->assertDatabaseCount('goods_receipts', 0);
    }

    public function test_goods_receipt_is_allowed_after_purchase_order_approval(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('admin-panel')->plainTextToken;
        $purchaseOrder = $this->createPurchaseOrder(PurchaseOrderStatus::Submitted);
        $item = $purchaseOrder->items()->first();

        $this->withToken($token)
            ->patchJson('/api/v1/purchase-orders/'.$purchaseOrder->id.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->withToken($token)
            ->postJson('/api/v1/goods-receipts', [
                'purchase_order_id' => $purchaseOrder->id,
                'items' => [[
                    'purchase_order_item_id' => $item->id,
                    'quantity_received' => 1,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.purchase_order.status', 'completed');
    }

    public function test_only_submitted_purchase_orders_can_be_approved(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('admin-panel')->plainTextToken;
        $draft = $this->createPurchaseOrder(PurchaseOrderStatus::Draft);
        $approved = $this->createPurchaseOrder(PurchaseOrderStatus::Approved);

        $this->withToken($token)
            ->patchJson('/api/v1/purchase-orders/'.$draft->id.'/approve')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only submitted purchase orders can be approved.');

        $this->withToken($token)
            ->patchJson('/api/v1/purchase-orders/'.$approved->id.'/approve')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only submitted purchase orders can be approved.');
    }

    private function createPurchaseOrder(PurchaseOrderStatus $status): PurchaseOrder
    {
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create(['current_stock' => 10]);

        $purchaseOrder = PurchaseOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'status' => $status,
            'subtotal' => 5,
            'submitted_at' => $status === PurchaseOrderStatus::Draft ? null : now()->subHour(),
            'approved_at' => $status === PurchaseOrderStatus::Approved ? now() : null,
        ]);

        PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_cost' => 5,
            'line_total' => 5,
        ]);

        return $purchaseOrder->load('items');
    }
}
