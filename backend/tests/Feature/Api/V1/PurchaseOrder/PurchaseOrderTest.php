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

class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_a_purchase_order(): void
    {
        $user = User::factory()->create();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'sku' => 'CLN-100001',
            'name' => 'Floor Cleaner',
        ]);

        $response = $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/purchase-orders', [
                'supplier_id' => $supplier->id,
                'currency' => 'USD',
                'notes' => 'Initial stock',
                'items' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 10,
                        'unit_cost' => 4.5,
                    ],
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Purchase order created successfully.')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.subtotal', '45.00')
            ->assertJsonPath('data.supplier.id', $supplier->id)
            ->assertJsonPath('data.supplier.name', $supplier->name)
            ->assertJsonPath('data.items.0.sku', 'CLN-100001')
            ->assertJsonPath('data.items.0.product_name', 'Floor Cleaner')
            ->assertJsonPath('data.items.0.quantity', 10)
            ->assertJsonPath('data.items.0.unit_cost', '4.50')
            ->assertJsonPath('data.items.0.line_total', '45.00');

        self::assertMatchesRegularExpression(
            '/^PO-'.now()->format('Y').'-\d{6}$/',
            $response->json('data.po_number'),
        );

        $this->assertDatabaseHas('purchase_orders', [
            'po_number' => $response->json('data.po_number'),
            'supplier_id' => $supplier->id,
            'status' => PurchaseOrderStatus::Draft->value,
            'subtotal' => 45,
        ]);
        $this->assertDatabaseHas('purchase_order_items', [
            'product_id' => $product->id,
            'sku' => 'CLN-100001',
            'product_name' => 'Floor Cleaner',
            'quantity' => 10,
            'unit_cost' => 4.5,
            'line_total' => 45,
        ]);
    }

    public function test_authenticated_user_can_update_a_draft_purchase_order(): void
    {
        $user = User::factory()->create();
        $supplier = Supplier::factory()->create();
        $otherSupplier = Supplier::factory()->create();
        $firstProduct = Product::factory()->create(['sku' => 'OLD-001', 'name' => 'Old Item']);
        $secondProduct = Product::factory()->create(['sku' => 'NEW-002', 'name' => 'New Item']);

        $purchaseOrder = PurchaseOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'status' => PurchaseOrderStatus::Draft,
            'currency' => 'USD',
            'subtotal' => 10,
        ]);
        PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'product_id' => $firstProduct->id,
            'sku' => $firstProduct->sku,
            'product_name' => $firstProduct->name,
            'quantity' => 2,
            'unit_cost' => 5,
            'line_total' => 10,
        ]);

        $response = $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->putJson('/api/v1/purchase-orders/'.$purchaseOrder->id, [
                'supplier_id' => $otherSupplier->id,
                'currency' => 'USD',
                'notes' => 'Revised order',
                'items' => [
                    [
                        'product_id' => $secondProduct->id,
                        'quantity' => 3,
                        'unit_cost' => 8,
                    ],
                ],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Purchase order updated successfully.')
            ->assertJsonPath('data.supplier.id', $otherSupplier->id)
            ->assertJsonPath('data.notes', 'Revised order')
            ->assertJsonPath('data.subtotal', '24.00')
            ->assertJsonPath('data.items.0.sku', 'NEW-002')
            ->assertJsonPath('data.items.0.quantity', 3);

        $this->assertDatabaseCount('purchase_order_items', 1);
        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $purchaseOrder->id,
            'product_id' => $secondProduct->id,
            'line_total' => 24,
        ]);
    }

    public function test_authenticated_user_can_submit_a_draft_purchase_order(): void
    {
        $user = User::factory()->create();
        $purchaseOrder = $this->createDraftPurchaseOrderWithItem();

        $response = $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->patchJson('/api/v1/purchase-orders/'.$purchaseOrder->id.'/submit');

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Purchase order submitted successfully.')
            ->assertJsonPath('data.status', 'submitted');

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $purchaseOrder->id,
            'status' => PurchaseOrderStatus::Submitted->value,
        ]);
        self::assertNotNull($purchaseOrder->fresh()->submitted_at);
    }

    public function test_authenticated_user_can_cancel_draft_or_submitted_purchase_orders(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('admin-panel')->plainTextToken;

        $draft = $this->createDraftPurchaseOrderWithItem();
        $submitted = $this->createDraftPurchaseOrderWithItem();
        $submitted->update([
            'status' => PurchaseOrderStatus::Submitted,
            'submitted_at' => now(),
        ]);

        $this->withToken($token)
            ->patchJson('/api/v1/purchase-orders/'.$draft->id.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->withToken($token)
            ->patchJson('/api/v1/purchase-orders/'.$submitted->id.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $draft->id,
            'status' => PurchaseOrderStatus::Cancelled->value,
        ]);
        $this->assertDatabaseHas('purchase_orders', [
            'id' => $submitted->id,
            'status' => PurchaseOrderStatus::Cancelled->value,
        ]);
    }

    public function test_authenticated_user_can_list_purchase_orders_newest_first(): void
    {
        $user = User::factory()->create();
        $older = PurchaseOrder::factory()->create([
            'created_at' => now()->subDay(),
        ]);
        $newer = PurchaseOrder::factory()->create([
            'created_at' => now(),
        ]);

        $response = $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/purchase-orders');

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Purchase orders retrieved successfully.')
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.items.0.po_number', $newer->po_number)
            ->assertJsonPath('data.items.1.po_number', $older->po_number);
    }

    public function test_authenticated_user_can_view_purchase_order_detail(): void
    {
        $user = User::factory()->create();
        $purchaseOrder = $this->createDraftPurchaseOrderWithItem();

        $response = $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/purchase-orders/'.$purchaseOrder->id);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Purchase order retrieved successfully.')
            ->assertJsonPath('data.po_number', $purchaseOrder->po_number)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.supplier.id', $purchaseOrder->supplier_id)
            ->assertJsonPath('data.items.0.product_id', $purchaseOrder->items()->first()->product_id);
    }

    public function test_purchase_order_create_returns_validation_errors(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/purchase-orders', [
                'currency' => 'usd',
                'items' => [],
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['supplier_id', 'currency', 'items']]);
    }

    public function test_lifecycle_rules_prevent_invalid_updates_submits_and_cancels(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('admin-panel')->plainTextToken;

        $submitted = $this->createDraftPurchaseOrderWithItem();
        $submitted->update([
            'status' => PurchaseOrderStatus::Submitted,
            'submitted_at' => now(),
        ]);

        $approved = $this->createDraftPurchaseOrderWithItem();
        $approved->update([
            'status' => PurchaseOrderStatus::Approved,
            'approved_at' => now(),
        ]);

        $this->withToken($token)
            ->putJson('/api/v1/purchase-orders/'.$submitted->id, [
                'notes' => 'Should fail',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('message', 'Only draft purchase orders can be updated.');

        $this->withToken($token)
            ->patchJson('/api/v1/purchase-orders/'.$submitted->id.'/submit')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only draft purchase orders can be submitted.');

        $this->withToken($token)
            ->patchJson('/api/v1/purchase-orders/'.$approved->id.'/cancel')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only draft or submitted purchase orders can be cancelled.');
    }

    public function test_authenticated_user_can_filter_purchase_orders_by_status_and_supplier(): void
    {
        $user = User::factory()->create();
        $supplier = Supplier::factory()->create();
        $otherSupplier = Supplier::factory()->create();

        PurchaseOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'status' => PurchaseOrderStatus::Draft,
        ]);
        PurchaseOrder::factory()->submitted()->create([
            'supplier_id' => $supplier->id,
        ]);
        PurchaseOrder::factory()->submitted()->create([
            'supplier_id' => $otherSupplier->id,
        ]);

        $token = $user->createToken('admin-panel')->plainTextToken;

        $byStatus = $this->withToken($token)->getJson('/api/v1/purchase-orders?status=submitted');
        $bySupplier = $this->withToken($token)->getJson(
            '/api/v1/purchase-orders?supplier_id='.$supplier->id.'&status=submitted',
        );

        $byStatus
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2);

        $bySupplier
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.supplier.id', $supplier->id)
            ->assertJsonPath('data.items.0.status', 'submitted');
    }

    public function test_guest_cannot_access_purchase_order_endpoints(): void
    {
        $purchaseOrder = $this->createDraftPurchaseOrderWithItem();

        $this->getJson('/api/v1/purchase-orders')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->getJson('/api/v1/purchase-orders/'.$purchaseOrder->id)
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->postJson('/api/v1/purchase-orders', [])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->putJson('/api/v1/purchase-orders/'.$purchaseOrder->id, [])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->patchJson('/api/v1/purchase-orders/'.$purchaseOrder->id.'/submit')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->patchJson('/api/v1/purchase-orders/'.$purchaseOrder->id.'/cancel')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    private function createDraftPurchaseOrderWithItem(): PurchaseOrder
    {
        $purchaseOrder = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Draft,
            'subtotal' => 20,
        ]);

        PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'quantity' => 2,
            'unit_cost' => 10,
            'line_total' => 20,
        ]);

        return $purchaseOrder->load(['supplier', 'items']);
    }
}
