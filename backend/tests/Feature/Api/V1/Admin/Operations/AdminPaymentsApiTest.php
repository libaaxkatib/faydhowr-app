<?php

namespace Tests\Feature\Api\V1\Admin\Operations;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStage;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Enums\QuotationStatus;
use App\Enums\ServiceMode;
use App\Enums\ServicePaymentType;
use App\Enums\StockMovementType;
use App\Enums\StoreOrderStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceModeOption;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminPaymentsApiTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

    public function test_listing_payments_requires_the_payments_view_permission(): void
    {
        $token = $this->tokenWithPermissions([]);

        $this->withToken($token)
            ->getJson('/api/v1/admin/payments')
            ->assertForbidden();
    }

    public function test_admin_can_list_payments_with_a_status_filter(): void
    {
        $order = $this->createServiceOrder();
        $this->createPayment($order, PaymentStatus::Pending, PaymentStage::Full);
        $paid = $this->createPayment($order, PaymentStatus::Paid, PaymentStage::Full);

        $token = $this->tokenWithPermissions([AdminPermission::PaymentsView]);

        $response = $this->withToken($token)
            ->getJson('/api/v1/admin/payments?status=paid')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.items.0.payment_number', $paid->payment_number);

        self::assertSame('paid', $response->json('data.items.0.status'));
    }

    public function test_list_rejects_an_invalid_status_filter(): void
    {
        $token = $this->tokenWithPermissions([AdminPermission::PaymentsView]);

        $this->withToken($token)
            ->getJson('/api/v1/admin/payments?status=unknown')
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_admin_can_view_a_payment_detail(): void
    {
        $order = $this->createServiceOrder();
        $payment = $this->createPayment($order, PaymentStatus::Pending, PaymentStage::Full);

        $token = $this->tokenWithPermissions([AdminPermission::PaymentsView]);

        $this->withToken($token)
            ->getJson("/api/v1/admin/payments/{$payment->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_number', $payment->payment_number)
            ->assertJsonPath('data.payable.type', 'order');
    }

    public function test_viewing_an_unknown_payment_returns_not_found(): void
    {
        $token = $this->tokenWithPermissions([AdminPermission::PaymentsView]);

        $this->withToken($token)
            ->getJson('/api/v1/admin/payments/999')
            ->assertNotFound()
            ->assertJsonPath('error_code', 'PAYMENT_NOT_FOUND');
    }

    public function test_admin_can_confirm_a_pending_payment(): void
    {
        $order = $this->createServiceOrder();
        $payment = $this->createPayment($order, PaymentStatus::Pending, PaymentStage::Full);

        $token = $this->tokenWithPermissions([AdminPermission::PaymentsConfirm]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/payments/{$payment->id}/confirm", [
                'notes' => 'Bank transfer reference TRX-99.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Paid->value,
        ]);
        $this->assertDatabaseHas('payment_status_histories', [
            'payment_id' => $payment->id,
            'status' => PaymentStatus::Paid->value,
            'changed_by_type' => 'admin',
            'notes' => 'Bank transfer reference TRX-99.',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment_confirm',
            'entity_type' => Payment::class,
            'entity_id' => $payment->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'title' => 'Payment Confirmed',
        ]);
    }

    public function test_confirming_a_paid_payment_is_idempotent(): void
    {
        $order = $this->createServiceOrder();
        $payment = $this->createPayment($order, PaymentStatus::Pending, PaymentStage::Full);

        $token = $this->tokenWithPermissions([AdminPermission::PaymentsConfirm]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/payments/{$payment->id}/confirm")
            ->assertOk();

        $receiptNumber = Payment::query()->findOrFail($payment->id)->receipt_number;

        $this->withToken($token)
            ->patchJson("/api/v1/admin/payments/{$payment->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.receipt_number', $receiptNumber);

        // The idempotent no-op emits no additional audit event or notification.
        self::assertSame(1, AuditLog::query()->where('action', 'payment_confirm')->count());
        self::assertSame(1, DB::table('notifications')->where('title', 'Payment Confirmed')->count());
    }

    public function test_a_failed_payment_cannot_be_confirmed(): void
    {
        $order = $this->createServiceOrder();
        $payment = $this->createPayment($order, PaymentStatus::Failed, PaymentStage::Full);

        $token = $this->tokenWithPermissions([AdminPermission::PaymentsConfirm]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/payments/{$payment->id}/confirm")
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'PAYMENT_STATE_INVALID');
    }

    public function test_confirming_requires_the_payments_confirm_permission(): void
    {
        $order = $this->createServiceOrder();
        $payment = $this->createPayment($order, PaymentStatus::Pending, PaymentStage::Full);

        $token = $this->tokenWithPermissions([AdminPermission::PaymentsView]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/payments/{$payment->id}/confirm")
            ->assertForbidden();
    }

    public function test_rejecting_a_payment_requires_a_reason(): void
    {
        $order = $this->createServiceOrder();
        $payment = $this->createPayment($order, PaymentStatus::Pending, PaymentStage::Full);

        $token = $this->tokenWithPermissions([AdminPermission::PaymentsConfirm]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/payments/{$payment->id}/reject")
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_admin_can_reject_an_active_payment(): void
    {
        $order = $this->createServiceOrder();
        $payment = $this->createPayment($order, PaymentStatus::Pending, PaymentStage::Full);

        $token = $this->tokenWithPermissions([AdminPermission::PaymentsConfirm]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/payments/{$payment->id}/reject", [
                'reason' => 'No matching bank transfer found.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Failed->value,
        ]);
        $this->assertDatabaseHas('payment_status_histories', [
            'payment_id' => $payment->id,
            'status' => PaymentStatus::Failed->value,
            'changed_by_type' => 'admin',
            'notes' => 'No matching bank transfer found.',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment_reject',
            'entity_type' => Payment::class,
            'entity_id' => $payment->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'title' => 'Payment Rejected',
        ]);

        // The service order itself stays untouched: the customer re-initializes.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::PendingPayment->value,
        ]);
    }

    public function test_a_paid_payment_cannot_be_rejected(): void
    {
        $order = $this->createServiceOrder();
        $payment = $this->createPayment($order, PaymentStatus::Paid, PaymentStage::Full);

        $token = $this->tokenWithPermissions([AdminPermission::PaymentsConfirm]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/payments/{$payment->id}/reject", [
                'reason' => 'Trying to reject a settled payment.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'PAYMENT_STATE_INVALID');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Paid->value,
        ]);
    }

    public function test_rejecting_a_cod_payment_cancels_the_order_and_restores_stock(): void
    {
        [$storeOrder, $payment, $product] = $this->createConfirmedCashOnDeliveryOrder();

        $token = $this->tokenWithPermissions([AdminPermission::PaymentsConfirm]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/payments/{$payment->id}/reject", [
                'reason' => 'Customer refused delivery.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed');

        $this->assertDatabaseHas('store_orders', [
            'id' => $storeOrder->id,
            'status' => StoreOrderStatus::Cancelled->value,
            'cancellation_reason' => 'Customer refused delivery.',
        ]);
        $this->assertDatabaseHas('store_order_status_histories', [
            'store_order_id' => $storeOrder->id,
            'status' => StoreOrderStatus::Cancelled->value,
            'changed_by_type' => 'admin',
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'current_stock' => 10,
        ]);
        $this->assertDatabaseHas('stock_ledgers', [
            'product_id' => $product->id,
            'movement_type' => StockMovementType::SaleReversal->value,
            'quantity' => 3,
            'reference_type' => StoreOrder::class,
            'reference_id' => $storeOrder->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'title' => 'Payment Rejected',
        ]);
    }

    public function test_rejecting_a_prepaid_store_order_payment_leaves_the_order_unchanged(): void
    {
        $profile = CustomerProfile::factory()->create();
        $storeOrder = StoreOrder::factory()->create([
            'customer_profile_id' => $profile->id,
            'status' => StoreOrderStatus::PendingPayment,
        ]);
        $payment = Payment::query()->create([
            'payment_number' => sprintf('PAY-%s-%06d', now()->format('Y'), 900000 + $this->sequence++),
            'customer_profile_id' => $profile->id,
            'payable_type' => StoreOrder::class,
            'payable_id' => $storeOrder->id,
            'status' => PaymentStatus::Pending,
            'payment_method' => 'bank_transfer',
            'payment_stage' => PaymentStage::Full,
            'amount' => '36.00',
            'currency' => 'USD',
        ]);

        $token = $this->tokenWithPermissions([AdminPermission::PaymentsConfirm]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/payments/{$payment->id}/reject", [
                'reason' => 'No transfer received.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('store_orders', [
            'id' => $storeOrder->id,
            'status' => StoreOrderStatus::PendingPayment->value,
        ]);
        $this->assertDatabaseMissing('stock_ledgers', [
            'movement_type' => StockMovementType::SaleReversal->value,
        ]);
    }

    /**
     * @return array{0: StoreOrder, 1: Payment, 2: Product}
     */
    private function createConfirmedCashOnDeliveryOrder(): array
    {
        $profile = CustomerProfile::factory()->create();
        $product = Product::factory()->create([
            'selling_price' => 12.00,
            'current_stock' => 7,
            'status' => ProductStatus::Active,
            'currency' => 'USD',
        ]);

        $storeOrder = StoreOrder::factory()->create([
            'customer_profile_id' => $profile->id,
            'status' => StoreOrderStatus::Confirmed,
        ]);
        StoreOrderItem::factory()->create([
            'store_order_id' => $storeOrder->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'unit_price_snapshot' => '12.00',
            'line_total_snapshot' => '36.00',
        ]);

        $payment = Payment::query()->create([
            'payment_number' => sprintf('PAY-%s-%06d', now()->format('Y'), 900000 + $this->sequence++),
            'customer_profile_id' => $profile->id,
            'payable_type' => StoreOrder::class,
            'payable_id' => $storeOrder->id,
            'status' => PaymentStatus::Pending,
            'payment_method' => 'cash_on_delivery',
            'payment_stage' => PaymentStage::Full,
            'amount' => '36.00',
            'currency' => 'USD',
        ]);

        return [$storeOrder, $payment, $product];
    }

    private function createServiceOrder(): Order
    {
        $profile = CustomerProfile::factory()->create();

        $category = ServiceCategory::query()->create([
            'name' => fake()->unique()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $service = Service::query()->create([
            'category_id' => $category->id,
            'name' => fake()->unique()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'currency' => 'USD',
            'requires_address' => true,
            'is_active' => true,
            'sort_order' => 0,
            'payment_type' => ServicePaymentType::FullBeforeService,
        ]);
        $mode = ServiceModeOption::query()->create([
            'service_id' => $service->id,
            'mode' => ServiceMode::OneTime,
            'is_active' => true,
        ]);

        $booking = Booking::query()->create([
            'booking_number' => sprintf('BK-%s-%06d', now()->format('Y'), $this->sequence++),
            'customer_profile_id' => $profile->id,
            'service_id' => $service->id,
            'service_mode_id' => $mode->id,
            'status' => BookingStatus::Accepted,
            'requested_date' => now()->addWeek()->toDateString(),
            'requested_time_window' => '09:00-12:00',
            'address_snapshot' => ['line1' => 'KM4 Road', 'city' => 'Mogadishu', 'country_code' => 'SO'],
        ]);

        $quotation = Quotation::query()->create([
            'quotation_number' => sprintf('QT-%s-%06d', now()->format('Y'), $this->sequence++),
            'customer_profile_id' => $profile->id,
            'booking_id' => $booking->id,
            'status' => QuotationStatus::Accepted,
            'currency' => 'USD',
            'subtotal' => '100.00',
            'discount_amount' => '10.00',
            'tax_amount' => '5.00',
            'total_amount' => '95.00',
            'payment_type' => ServicePaymentType::FullBeforeService,
            'valid_until' => now()->addWeek(),
            'accepted_at' => now(),
        ]);

        return Order::query()->create([
            'order_number' => sprintf('ORD-%s-%06d', now()->format('Y'), $this->sequence++),
            'customer_profile_id' => $profile->id,
            'quotation_id' => $quotation->id,
            'status' => OrderStatus::PendingPayment,
            'currency' => 'USD',
            'subtotal' => '100.00',
            'discount_amount' => '10.00',
            'tax_amount' => '5.00',
            'total_amount' => '95.00',
        ]);
    }

    private function createPayment(Order $order, PaymentStatus $status, PaymentStage $stage): Payment
    {
        return Payment::query()->create([
            'payment_number' => sprintf('PAY-%s-%06d', now()->format('Y'), 800000 + $this->sequence++),
            'customer_profile_id' => $order->customer_profile_id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'status' => $status,
            'payment_method' => 'evc_plus',
            'payment_stage' => $stage,
            'amount' => '95.00',
            'currency' => 'USD',
            'paid_at' => $status === PaymentStatus::Paid ? now() : null,
        ]);
    }

    /**
     * @param  list<AdminPermission>  $permissions
     */
    private function tokenWithPermissions(array $permissions, AdminRole $role = AdminRole::Manager): string
    {
        $admin = Admin::factory()->create(['role' => $role]);

        foreach ($permissions as $permission) {
            $permissionId = Permission::query()->where('key', $permission->value)->value('id');

            DB::table('admin_permissions')->insert([
                'admin_id' => $admin->id,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $admin->createToken('admin-panel')->plainTextToken;
    }
}
