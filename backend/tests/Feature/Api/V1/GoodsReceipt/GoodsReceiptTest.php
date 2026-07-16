<?php

namespace Tests\Feature\Api\V1\GoodsReceipt;

use App\Enums\PurchaseOrderStatus;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoodsReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_a_full_goods_receipt(): void
    {
        $user = User::factory()->create();
        [$purchaseOrder, $item] = $this->createReceivablePurchaseOrder(quantity: 10);

        $stockBefore = Product::query()->findOrFail($item->product_id)->current_stock;

        $response = $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/goods-receipts', [
                'purchase_order_id' => $purchaseOrder->id,
                'items' => [
                    [
                        'purchase_order_item_id' => $item->id,
                        'quantity_received' => 10,
                    ],
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Goods receipt created successfully.')
            ->assertJsonPath('data.supplier.id', $purchaseOrder->supplier_id)
            ->assertJsonPath('data.purchase_order.id', $purchaseOrder->id)
            ->assertJsonPath('data.purchase_order.status', 'completed')
            ->assertJsonPath('data.status_summary.purchase_order_status', 'completed')
            ->assertJsonPath('data.items.0.sku', $item->sku)
            ->assertJsonPath('data.items.0.product_name', $item->product_name)
            ->assertJsonPath('data.items.0.quantity_received', 10)
            ->assertJsonPath('data.items.0.unit_cost', '5.00');

        self::assertMatchesRegularExpression(
            '/^GR-'.now()->format('Y').'-\d{6}$/',
            $response->json('data.gr_number'),
        );

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $purchaseOrder->id,
            'status' => PurchaseOrderStatus::Completed->value,
        ]);
        $this->assertDatabaseHas('goods_receipt_items', [
            'purchase_order_item_id' => $item->id,
            'sku' => $item->sku,
            'product_name' => $item->product_name,
            'quantity_received' => 10,
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $item->product_id,
            'current_stock' => $stockBefore + 10,
        ]);
        $this->assertDatabaseCount('stock_ledgers', 1);
    }

    public function test_authenticated_user_can_create_a_partial_goods_receipt(): void
    {
        $user = User::factory()->create();
        [$purchaseOrder, $item] = $this->createReceivablePurchaseOrder(quantity: 10);

        $response = $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/goods-receipts', [
                'purchase_order_id' => $purchaseOrder->id,
                'items' => [
                    [
                        'purchase_order_item_id' => $item->id,
                        'quantity_received' => 4,
                        'unit_cost' => 6.25,
                    ],
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.purchase_order.status', 'partially_received')
            ->assertJsonPath('data.status_summary.purchase_order_status', 'partially_received')
            ->assertJsonPath('data.items.0.quantity_received', 4)
            ->assertJsonPath('data.items.0.unit_cost', '6.25');

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $purchaseOrder->id,
            'status' => PurchaseOrderStatus::PartiallyReceived->value,
        ]);
        $this->assertDatabaseCount('stock_ledgers', 1);
    }

    public function test_goods_receipt_create_returns_validation_errors_for_over_receipt(): void
    {
        $user = User::factory()->create();
        [$purchaseOrder, $item] = $this->createReceivablePurchaseOrder(quantity: 5);

        $response = $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/goods-receipts', [
                'purchase_order_id' => $purchaseOrder->id,
                'items' => [
                    [
                        'purchase_order_item_id' => $item->id,
                        'quantity_received' => 6,
                    ],
                ],
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath(
                'message',
                'Received quantity cannot exceed the remaining purchase order quantity.',
            );

        $this->assertDatabaseCount('goods_receipts', 0);
    }

    public function test_goods_receipt_create_requires_valid_payload(): void
    {
        $user = User::factory()->create();

        $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/goods-receipts', [
                'items' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['purchase_order_id', 'items']]);
    }

    public function test_authenticated_user_can_list_goods_receipts_newest_first(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('admin-panel')->plainTextToken;

        [$firstOrder, $firstItem] = $this->createReceivablePurchaseOrder(quantity: 2);
        [$secondOrder, $secondItem] = $this->createReceivablePurchaseOrder(quantity: 2);

        $olderResponse = $this->withToken($token)->postJson('/api/v1/goods-receipts', [
            'purchase_order_id' => $firstOrder->id,
            'items' => [['purchase_order_item_id' => $firstItem->id, 'quantity_received' => 2]],
        ])->assertCreated();

        $newerResponse = $this->withToken($token)->postJson('/api/v1/goods-receipts', [
            'purchase_order_id' => $secondOrder->id,
            'items' => [['purchase_order_item_id' => $secondItem->id, 'quantity_received' => 2]],
        ])->assertCreated();

        GoodsReceipt::query()
            ->where('gr_number', $olderResponse->json('data.gr_number'))
            ->update(['created_at' => now()->subMinute()]);
        GoodsReceipt::query()
            ->where('gr_number', $newerResponse->json('data.gr_number'))
            ->update(['created_at' => now()]);

        $response = $this->withToken($token)->getJson('/api/v1/goods-receipts');

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Goods receipts retrieved successfully.')
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.items.0.gr_number', $newerResponse->json('data.gr_number'))
            ->assertJsonPath('data.items.1.gr_number', $olderResponse->json('data.gr_number'));
    }

    public function test_authenticated_user_can_view_goods_receipt_detail(): void
    {
        $user = User::factory()->create();
        [$purchaseOrder, $item] = $this->createReceivablePurchaseOrder(quantity: 3);

        $created = $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/goods-receipts', [
                'purchase_order_id' => $purchaseOrder->id,
                'items' => [
                    ['purchase_order_item_id' => $item->id, 'quantity_received' => 3],
                ],
            ])
            ->assertCreated();

        $grNumber = $created->json('data.gr_number');
        $goodsReceiptId = GoodsReceipt::query()
            ->where('gr_number', $grNumber)
            ->value('id');

        $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/goods-receipts/'.$goodsReceiptId)
            ->assertOk()
            ->assertJsonPath('message', 'Goods receipt retrieved successfully.')
            ->assertJsonPath('data.gr_number', $grNumber)
            ->assertJsonPath('data.purchase_order.po_number', $purchaseOrder->po_number)
            ->assertJsonPath('data.items.0.quantity_received', 3);
    }

    public function test_authenticated_user_can_filter_goods_receipts_by_supplier_and_purchase_order(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('admin-panel')->plainTextToken;

        [$firstOrder, $firstItem] = $this->createReceivablePurchaseOrder(quantity: 2);
        [$secondOrder, $secondItem] = $this->createReceivablePurchaseOrder(quantity: 2);

        $this->withToken($token)->postJson('/api/v1/goods-receipts', [
            'purchase_order_id' => $firstOrder->id,
            'items' => [['purchase_order_item_id' => $firstItem->id, 'quantity_received' => 2]],
        ])->assertCreated();

        $this->withToken($token)->postJson('/api/v1/goods-receipts', [
            'purchase_order_id' => $secondOrder->id,
            'items' => [['purchase_order_item_id' => $secondItem->id, 'quantity_received' => 2]],
        ])->assertCreated();

        $bySupplier = $this->withToken($token)->getJson(
            '/api/v1/goods-receipts?supplier_id='.$firstOrder->supplier_id,
        );
        $byPurchaseOrder = $this->withToken($token)->getJson(
            '/api/v1/goods-receipts?purchase_order_id='.$secondOrder->id,
        );

        $bySupplier
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.supplier.id', $firstOrder->supplier_id);

        $byPurchaseOrder
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.purchase_order.id', $secondOrder->id);
    }

    public function test_goods_receipt_rejects_invalid_purchase_order_lifecycle(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('admin-panel')->plainTextToken;

        $draft = $this->createPurchaseOrderWithItem(PurchaseOrderStatus::Draft, quantity: 2);
        $cancelled = $this->createPurchaseOrderWithItem(PurchaseOrderStatus::Cancelled, quantity: 2);

        $this->withToken($token)
            ->postJson('/api/v1/goods-receipts', [
                'purchase_order_id' => $draft['purchase_order']->id,
                'items' => [[
                    'purchase_order_item_id' => $draft['item']->id,
                    'quantity_received' => 1,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Goods receipts can only be created for approved or partially received purchase orders.',
            );

        $this->withToken($token)
            ->postJson('/api/v1/goods-receipts', [
                'purchase_order_id' => $cancelled['purchase_order']->id,
                'items' => [[
                    'purchase_order_item_id' => $cancelled['item']->id,
                    'quantity_received' => 1,
                ]],
            ])
            ->assertUnprocessable();
    }

    public function test_guest_cannot_access_goods_receipt_endpoints(): void
    {
        [$purchaseOrder, $item] = $this->createReceivablePurchaseOrder(quantity: 1);

        $this->getJson('/api/v1/goods-receipts')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->getJson('/api/v1/goods-receipts/1')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->postJson('/api/v1/goods-receipts', [
            'purchase_order_id' => $purchaseOrder->id,
            'items' => [[
                'purchase_order_item_id' => $item->id,
                'quantity_received' => 1,
            ]],
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    /**
     * @return array{0: PurchaseOrder, 1: PurchaseOrderItem}
     */
    private function createReceivablePurchaseOrder(int $quantity): array
    {
        $created = $this->createPurchaseOrderWithItem(PurchaseOrderStatus::Approved, $quantity);

        return [$created['purchase_order'], $created['item']];
    }

    /**
     * @return array{purchase_order: PurchaseOrder, item: PurchaseOrderItem}
     */
    private function createPurchaseOrderWithItem(PurchaseOrderStatus $status, int $quantity): array
    {
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'sku' => 'GR-SKU-'.fake()->unique()->numerify('######'),
            'name' => 'Receipt Product',
            'current_stock' => 50,
        ]);

        $purchaseOrder = PurchaseOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'status' => $status,
            'currency' => 'USD',
            'subtotal' => $quantity * 5,
            'submitted_at' => in_array($status, [
                PurchaseOrderStatus::Draft,
            ], true) ? null : now(),
            'approved_at' => in_array($status, [
                PurchaseOrderStatus::Approved,
                PurchaseOrderStatus::PartiallyReceived,
                PurchaseOrderStatus::Completed,
            ], true) ? now() : null,
            'cancelled_at' => $status === PurchaseOrderStatus::Cancelled ? now() : null,
        ]);

        $item = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'product_name' => $product->name,
            'quantity' => $quantity,
            'unit_cost' => 5,
            'line_total' => $quantity * 5,
        ]);

        return [
            'purchase_order' => $purchaseOrder,
            'item' => $item,
        ];
    }
}
