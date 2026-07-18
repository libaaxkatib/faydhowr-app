<?php

namespace Tests\Feature\Api\V1\Admin\Operations;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStage;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Enums\ServiceMode;
use App\Enums\ServicePaymentType;
use App\Models\Admin;
use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Quotation;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceModeOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminBookingsApiTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

    public function test_listing_bookings_requires_the_bookings_view_permission(): void
    {
        $token = $this->tokenWithPermissions([]);

        $this->withToken($token)
            ->getJson('/api/v1/admin/bookings')
            ->assertForbidden();
    }

    public function test_admin_can_list_bookings_with_a_status_filter(): void
    {
        [$accepted] = $this->createBookingWithQuotation(ServicePaymentType::PayAfterService, BookingStatus::Accepted);
        $this->createBookingWithQuotation(ServicePaymentType::PayAfterService, BookingStatus::Submitted);

        $token = $this->tokenWithPermissions([AdminPermission::BookingsView]);

        $this->withToken($token)
            ->getJson('/api/v1/admin/bookings?status=accepted')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.items.0.booking_number', $accepted->booking_number);
    }

    public function test_booking_detail_exposes_payments_and_payment_gate_flags(): void
    {
        [$booking, $quotation] = $this->createBookingWithQuotation(ServicePaymentType::Deposit, BookingStatus::Accepted);
        $order = $this->createOrder($quotation);
        $this->createPayment($order, PaymentStatus::Paid, PaymentStage::Deposit, '28.50');

        $token = $this->tokenWithPermissions([AdminPermission::BookingsView]);

        $this->withToken($token)
            ->getJson("/api/v1/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.booking_number', $booking->booking_number)
            ->assertJsonPath('data.can_schedule', true)
            ->assertJsonPath('data.can_close', false)
            ->assertJsonPath('data.payments.0.payment_stage', 'deposit')
            ->assertJsonPath('data.quotations.0.quotation_number', $quotation->quotation_number);
    }

    public function test_viewing_an_unknown_booking_returns_not_found(): void
    {
        $token = $this->tokenWithPermissions([AdminPermission::BookingsView]);

        $this->withToken($token)
            ->getJson('/api/v1/admin/bookings/999')
            ->assertNotFound()
            ->assertJsonPath('error_code', 'BOOKING_NOT_FOUND');
    }

    public function test_admin_can_schedule_an_accepted_booking(): void
    {
        [$booking] = $this->createBookingWithQuotation(ServicePaymentType::PayAfterService, BookingStatus::Accepted);

        $token = $this->tokenWithPermissions([AdminPermission::BookingsManage]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/bookings/{$booking->id}/schedule", [
                'scheduled_start_at' => now()->addDay()->toISOString(),
                'scheduled_end_at' => now()->addDay()->addHours(3)->toISOString(),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'booking_schedule',
            'entity_type' => Booking::class,
            'entity_id' => $booking->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'title' => 'Booking Scheduled',
        ]);
    }

    public function test_scheduling_validates_the_time_window(): void
    {
        [$booking] = $this->createBookingWithQuotation(ServicePaymentType::PayAfterService, BookingStatus::Accepted);

        $token = $this->tokenWithPermissions([AdminPermission::BookingsManage]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/bookings/{$booking->id}/schedule", [
                'scheduled_start_at' => now()->addDay()->toISOString(),
                'scheduled_end_at' => now()->addDay()->subHour()->toISOString(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_scheduling_is_blocked_until_the_required_payment_is_confirmed(): void
    {
        [$booking] = $this->createBookingWithQuotation(ServicePaymentType::Deposit, BookingStatus::Accepted);

        $token = $this->tokenWithPermissions([AdminPermission::BookingsManage]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/bookings/{$booking->id}/schedule", [
                'scheduled_start_at' => now()->addDay()->toISOString(),
                'scheduled_end_at' => now()->addDay()->addHours(3)->toISOString(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'BOOKING_STATE_INVALID');
    }

    public function test_admin_can_start_a_scheduled_booking(): void
    {
        [$booking] = $this->createBookingWithQuotation(ServicePaymentType::PayAfterService, BookingStatus::Scheduled);

        $token = $this->tokenWithPermissions([AdminPermission::BookingsManage]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/bookings/{$booking->id}/start")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'booking_start',
            'entity_type' => Booking::class,
            'entity_id' => $booking->id,
        ]);
    }

    public function test_only_scheduled_bookings_can_be_started(): void
    {
        [$booking] = $this->createBookingWithQuotation(ServicePaymentType::PayAfterService, BookingStatus::Accepted);

        $token = $this->tokenWithPermissions([AdminPermission::BookingsManage]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/bookings/{$booking->id}/start")
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'BOOKING_STATE_INVALID');
    }

    public function test_admin_can_complete_an_in_progress_booking(): void
    {
        [$booking] = $this->createBookingWithQuotation(ServicePaymentType::PayAfterService, BookingStatus::InProgress);

        $token = $this->tokenWithPermissions([AdminPermission::BookingsManage]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/bookings/{$booking->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'booking_complete',
            'entity_type' => Booking::class,
            'entity_id' => $booking->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'title' => 'Booking Completed',
        ]);
    }

    public function test_closing_is_blocked_until_all_required_payments_are_confirmed(): void
    {
        [$booking] = $this->createBookingWithQuotation(ServicePaymentType::Deposit, BookingStatus::Completed);

        $token = $this->tokenWithPermissions([AdminPermission::BookingsManage]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/bookings/{$booking->id}/close")
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'BOOKING_STATE_INVALID');
    }

    public function test_admin_can_close_a_completed_booking_after_the_final_payment(): void
    {
        [$booking, $quotation] = $this->createBookingWithQuotation(ServicePaymentType::Deposit, BookingStatus::Completed);
        $order = $this->createOrder($quotation);
        $this->createPayment($order, PaymentStatus::Paid, PaymentStage::Deposit, '28.50');
        $this->createPayment($order, PaymentStatus::Paid, PaymentStage::Balance, '66.50');

        $token = $this->tokenWithPermissions([AdminPermission::BookingsManage]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/bookings/{$booking->id}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'booking_close',
            'entity_type' => Booking::class,
            'entity_id' => $booking->id,
        ]);
    }

    public function test_cancelling_requires_a_reason(): void
    {
        [$booking] = $this->createBookingWithQuotation(ServicePaymentType::PayAfterService, BookingStatus::Accepted);

        $token = $this->tokenWithPermissions([AdminPermission::BookingsManage]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/bookings/{$booking->id}/cancel")
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_cancelling_keeps_paid_payments_and_fails_active_payments(): void
    {
        [$booking, $quotation] = $this->createBookingWithQuotation(ServicePaymentType::Deposit, BookingStatus::Accepted);
        $order = $this->createOrder($quotation);
        $paidDeposit = $this->createPayment($order, PaymentStatus::Paid, PaymentStage::Deposit, '28.50');
        $pendingBalance = $this->createPayment($order, PaymentStatus::Pending, PaymentStage::Balance, '66.50');

        $token = $this->tokenWithPermissions([AdminPermission::BookingsManage]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/bookings/{$booking->id}/cancel", [
                'cancellation_reason' => 'Customer requested cancellation.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => BookingStatus::Cancelled->value,
            'cancellation_reason' => 'Customer requested cancellation.',
        ]);
        $this->assertDatabaseHas('payments', [
            'id' => $paidDeposit->id,
            'status' => PaymentStatus::Paid->value,
        ]);
        $this->assertDatabaseHas('payments', [
            'id' => $pendingBalance->id,
            'status' => PaymentStatus::Failed->value,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'booking_cancel',
            'entity_type' => Booking::class,
            'entity_id' => $booking->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'title' => 'Booking Cancelled',
        ]);
    }

    public function test_a_completed_booking_cannot_be_cancelled(): void
    {
        [$booking] = $this->createBookingWithQuotation(ServicePaymentType::PayAfterService, BookingStatus::Completed);

        $token = $this->tokenWithPermissions([AdminPermission::BookingsManage]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/bookings/{$booking->id}/cancel", [
                'cancellation_reason' => 'Too late to cancel.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'BOOKING_STATE_INVALID');
    }

    public function test_there_is_no_booking_accept_endpoint(): void
    {
        [$booking] = $this->createBookingWithQuotation(ServicePaymentType::PayAfterService, BookingStatus::Submitted);

        $token = $this->tokenWithPermissions([AdminPermission::BookingsManage]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/bookings/{$booking->id}/accept")
            ->assertNotFound();
    }

    public function test_mutations_require_the_bookings_manage_permission(): void
    {
        [$booking] = $this->createBookingWithQuotation(ServicePaymentType::PayAfterService, BookingStatus::Accepted);

        $token = $this->tokenWithPermissions([AdminPermission::BookingsView]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/bookings/{$booking->id}/schedule", [
                'scheduled_start_at' => now()->addDay()->toISOString(),
                'scheduled_end_at' => now()->addDay()->addHours(3)->toISOString(),
            ])
            ->assertForbidden();
    }

    /**
     * @return array{0: Booking, 1: Quotation}
     */
    private function createBookingWithQuotation(
        ServicePaymentType $paymentType,
        BookingStatus $bookingStatus,
    ): array {
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
            'payment_type' => $paymentType,
            'deposit_percentage' => $paymentType === ServicePaymentType::Deposit ? 30 : null,
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
            'status' => $bookingStatus,
            'requested_date' => now()->addWeek()->toDateString(),
            'requested_time_window' => '09:00-12:00',
            'scheduled_start_at' => $bookingStatus === BookingStatus::Scheduled ? now()->addDay() : null,
            'scheduled_end_at' => $bookingStatus === BookingStatus::Scheduled ? now()->addDay()->addHours(3) : null,
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
            'payment_type' => $paymentType,
            'deposit_percentage' => $paymentType === ServicePaymentType::Deposit ? 30 : null,
            'deposit_amount' => $paymentType === ServicePaymentType::Deposit ? '28.50' : null,
            'remaining_amount' => match ($paymentType) {
                ServicePaymentType::Deposit => '66.50',
                ServicePaymentType::PayAfterService => '95.00',
                default => null,
            },
            'valid_until' => now()->addWeek(),
            'accepted_at' => now(),
        ]);

        return [$booking, $quotation];
    }

    private function createOrder(Quotation $quotation): Order
    {
        return Order::query()->create([
            'order_number' => sprintf('ORD-%s-%06d', now()->format('Y'), $this->sequence++),
            'customer_profile_id' => $quotation->customer_profile_id,
            'quotation_id' => $quotation->id,
            'status' => OrderStatus::PendingPayment,
            'currency' => $quotation->currency,
            'subtotal' => $quotation->subtotal,
            'discount_amount' => $quotation->discount_amount,
            'tax_amount' => $quotation->tax_amount,
            'total_amount' => $quotation->total_amount,
        ]);
    }

    private function createPayment(
        Order $order,
        PaymentStatus $status,
        PaymentStage $stage,
        string $amount,
    ): Payment {
        return Payment::query()->create([
            'payment_number' => sprintf('PAY-%s-%06d', now()->format('Y'), 800000 + $this->sequence++),
            'customer_profile_id' => $order->customer_profile_id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'status' => $status,
            'payment_method' => 'evc_plus',
            'payment_stage' => $stage,
            'amount' => $amount,
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
