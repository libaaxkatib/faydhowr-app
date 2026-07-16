<?php

namespace Tests\Feature\Api\V1\Inventory;

use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\StockMovementType;
use App\Enums\StoreOrderStatus;
use App\Events\Payment\PaymentPaid;
use App\Models\CustomerProfile;
use App\Models\GoodsReceipt;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class InventoryStockUpdateTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

    public function test_goods_receipt_increases_product_stock(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['current_stock' => 20]);
        [$purchaseOrder, $item] = $this->createSubmittedPurchaseOrder($product, quantity: 7);

        $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/goods-receipts', [
                'purchase_order_id' => $purchaseOrder->id,
                'items' => [[
                    'purchase_order_item_id' => $item->id,
                    'quantity_received' => 7,
                ]],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'current_stock' => 27,
        ]);
    }

    public function test_goods_receipt_creates_purchase_receipt_ledger_entries(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['current_stock' => 5]);
        [$purchaseOrder, $item] = $this->createSubmittedPurchaseOrder($product, quantity: 3);

        $response = $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/goods-receipts', [
                'purchase_order_id' => $purchaseOrder->id,
                'items' => [[
                    'purchase_order_item_id' => $item->id,
                    'quantity_received' => 3,
                ]],
            ])
            ->assertCreated();

        $goodsReceipt = GoodsReceipt::query()
            ->where('gr_number', $response->json('data.gr_number'))
            ->firstOrFail();

        $this->assertDatabaseHas('stock_ledgers', [
            'product_id' => $product->id,
            'movement_type' => StockMovementType::PurchaseReceipt->value,
            'quantity' => 3,
            'reference_type' => GoodsReceipt::class,
            'reference_id' => $goodsReceipt->id,
        ]);
        $this->assertDatabaseCount('stock_ledgers', 1);
    }

    public function test_store_order_paid_payment_decreases_product_stock(): void
    {
        Event::fake([PaymentPaid::class]);

        $product = Product::factory()->create(['current_stock' => 15]);
        $payment = $this->createProcessingStoreOrderPayment($product, quantity: 4, stock: 15);

        $this->postWebhook($this->webhookPayload($payment->gateway_reference, 'success'))
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'current_stock' => 11,
        ]);
        $this->assertDatabaseHas('store_orders', [
            'id' => $payment->payable_id,
            'status' => StoreOrderStatus::Confirmed->value,
        ]);
    }

    public function test_store_order_paid_payment_creates_customer_sale_ledger_entries(): void
    {
        Event::fake([PaymentPaid::class]);

        $product = Product::factory()->create(['current_stock' => 10]);
        $payment = $this->createProcessingStoreOrderPayment($product, quantity: 2, stock: 10);

        $this->postWebhook($this->webhookPayload($payment->gateway_reference, 'success'))
            ->assertOk();

        $this->assertDatabaseHas('stock_ledgers', [
            'product_id' => $product->id,
            'movement_type' => StockMovementType::CustomerSale->value,
            'quantity' => -2,
            'reference_type' => StoreOrder::class,
            'reference_id' => $payment->payable_id,
        ]);
        $this->assertDatabaseCount('stock_ledgers', 1);
    }

    public function test_store_order_paid_payment_rejects_insufficient_stock(): void
    {
        Event::fake([PaymentPaid::class]);

        $product = Product::factory()->create(['current_stock' => 1]);
        $payment = $this->createProcessingStoreOrderPayment($product, quantity: 5, stock: 1);

        $this->postWebhook($this->webhookPayload($payment->gateway_reference, 'success'))
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('message', 'Insufficient stock to fulfill the paid store order.');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Processing->value,
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'current_stock' => 1,
        ]);
        $this->assertDatabaseHas('store_orders', [
            'id' => $payment->payable_id,
            'status' => StoreOrderStatus::PendingPayment->value,
        ]);
        $this->assertDatabaseCount('stock_ledgers', 0);
    }

    public function test_goods_receipt_stock_update_rolls_back_atomically_when_product_is_missing(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['current_stock' => 8]);
        [$purchaseOrder, $item] = $this->createSubmittedPurchaseOrder($product, quantity: 2);
        $product->delete();

        $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/goods-receipts', [
                'purchase_order_id' => $purchaseOrder->id,
                'items' => [[
                    'purchase_order_item_id' => $item->id,
                    'quantity_received' => 2,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');

        $this->assertDatabaseCount('goods_receipts', 0);
        $this->assertDatabaseCount('stock_ledgers', 0);
        $this->assertSame(8, Product::withTrashed()->findOrFail($product->id)->current_stock);
    }

    public function test_duplicate_paid_webhook_does_not_double_deduct_stock(): void
    {
        Event::fake([PaymentPaid::class]);

        $product = Product::factory()->create(['current_stock' => 10]);
        $payment = $this->createProcessingStoreOrderPayment($product, quantity: 3, stock: 10);
        $payload = $this->webhookPayload($payment->gateway_reference, 'success');

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'current_stock' => 7,
        ]);
        $this->assertDatabaseCount('stock_ledgers', 1);
        $this->assertDatabaseCount('store_order_status_histories', 1);
        Event::assertDispatchedTimes(PaymentPaid::class, 1);
    }

    public function test_guest_cannot_create_goods_receipt_stock_update(): void
    {
        $product = Product::factory()->create(['current_stock' => 5]);
        [$purchaseOrder, $item] = $this->createSubmittedPurchaseOrder($product, quantity: 1);

        $this->postJson('/api/v1/goods-receipts', [
            'purchase_order_id' => $purchaseOrder->id,
            'items' => [[
                'purchase_order_item_id' => $item->id,
                'quantity_received' => 1,
            ]],
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'current_stock' => 5,
        ]);
        $this->assertDatabaseCount('stock_ledgers', 0);
    }

    /**
     * @return array{0: PurchaseOrder, 1: PurchaseOrderItem}
     */
    private function createSubmittedPurchaseOrder(Product $product, int $quantity): array
    {
        $supplier = Supplier::factory()->create();
        $purchaseOrder = PurchaseOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'status' => PurchaseOrderStatus::Approved,
            'submitted_at' => now()->subHour(),
            'approved_at' => now(),
            'subtotal' => $quantity * 5,
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

        return [$purchaseOrder, $item];
    }

    private function createProcessingStoreOrderPayment(Product $product, int $quantity, int $stock): Payment
    {
        $product->update(['current_stock' => $stock]);

        $profile = CustomerProfile::factory()->create();
        $storeOrder = StoreOrder::factory()->create([
            'customer_profile_id' => $profile->id,
            'status' => StoreOrderStatus::PendingPayment,
            'subtotal' => $quantity * 10,
        ]);

        StoreOrderItem::factory()->create([
            'store_order_id' => $storeOrder->id,
            'product_id' => $product->id,
            'sku_snapshot' => $product->sku,
            'product_name_snapshot' => $product->name,
            'quantity' => $quantity,
            'unit_price_snapshot' => 10,
            'line_total_snapshot' => $quantity * 10,
        ]);

        $transactionReference = 'TXN-STO-'.$this->sequence;

        $payment = Payment::query()->create([
            'payment_number' => sprintf('PAY-%s-%06d', now()->format('Y'), $this->sequence++),
            'customer_profile_id' => $profile->id,
            'payable_type' => StoreOrder::class,
            'payable_id' => $storeOrder->id,
            'status' => PaymentStatus::Processing,
            'amount' => $storeOrder->subtotal,
            'currency' => $storeOrder->currency,
            'gateway' => 'manual',
            'gateway_reference' => $transactionReference,
        ]);

        $payment->transactions()->create([
            'gateway' => 'manual',
            'transaction_reference' => $transactionReference,
            'status' => PaymentStatus::Processing->value,
            'processed_at' => now(),
        ]);

        return $payment;
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function postWebhook(array $payload)
    {
        return $this
            ->withHeader('X-Payment-Signature', $this->signPayload($payload))
            ->postJson('/api/v1/payments/webhook', $payload);
    }

    /**
     * @return array{gateway: string, transaction_reference: string, status: string}
     */
    private function webhookPayload(string $transactionReference, string $status): array
    {
        return [
            'gateway' => 'manual',
            'transaction_reference' => $transactionReference,
            'status' => $status,
        ];
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function signPayload(array $payload): string
    {
        $rawPayload = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return hash_hmac(
            'sha256',
            $rawPayload,
            (string) config('payments.gateways.manual.webhook_secret'),
        );
    }
}
