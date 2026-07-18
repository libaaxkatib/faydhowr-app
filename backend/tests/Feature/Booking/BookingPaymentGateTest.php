<?php

namespace Tests\Feature\Booking;

use App\Actions\Booking\CloseBookingAction;
use App\Actions\Booking\ScheduleBookingAction;
use App\Actions\Payment\ConfirmOfflinePaymentAction;
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
use App\Models\Quotation;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceModeOption;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingPaymentGateTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

    public function test_deposit_policy_booking_cannot_be_scheduled_before_the_deposit_is_confirmed(): void
    {
        [$booking] = $this->createAcceptedBookingWithQuotation(ServicePaymentType::Deposit);
        $admin = Admin::factory()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('The required payment must be confirmed before the booking can be scheduled.');

        app(ScheduleBookingAction::class)->handle(
            $admin,
            $booking->id,
            now()->addDay(),
            now()->addDay()->addHours(3),
        );
    }

    public function test_deposit_policy_booking_can_be_scheduled_after_the_deposit_is_confirmed(): void
    {
        [$booking, $quotation] = $this->createAcceptedBookingWithQuotation(ServicePaymentType::Deposit);
        $order = $this->createOrder($quotation);
        $this->createPaidPayment($order, PaymentStage::Deposit, '28.50');
        $admin = Admin::factory()->create();

        $scheduled = app(ScheduleBookingAction::class)->handle(
            $admin,
            $booking->id,
            now()->addDay(),
            now()->addDay()->addHours(3),
        );

        $this->assertSame(BookingStatus::Scheduled, $scheduled->status);
        $this->assertNotNull($scheduled->scheduled_start_at);
        $this->assertDatabaseHas('booking_status_histories', [
            'booking_id' => $booking->id,
            'status' => BookingStatus::Scheduled->value,
            'changed_by_type' => 'admin',
            'changed_by_id' => $admin->id,
        ]);
    }

    public function test_full_before_service_booking_requires_the_full_payment_before_scheduling(): void
    {
        [$booking, $quotation] = $this->createAcceptedBookingWithQuotation(ServicePaymentType::FullBeforeService);
        $order = $this->createOrder($quotation);
        $admin = Admin::factory()->create();
        $schedule = app(ScheduleBookingAction::class);

        try {
            $schedule->handle($admin, $booking->id, now()->addDay(), now()->addDay()->addHours(3));
            self::fail('Scheduling should have been blocked before the full payment.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'The required payment must be confirmed before the booking can be scheduled.',
                $exception->getMessage(),
            );
        }

        $this->createPaidPayment($order, PaymentStage::Full, '95.00');

        $scheduled = $schedule->handle($admin, $booking->id, now()->addDay(), now()->addDay()->addHours(3));

        $this->assertSame(BookingStatus::Scheduled, $scheduled->status);
    }

    public function test_pay_after_service_booking_can_be_scheduled_without_payment(): void
    {
        [$booking] = $this->createAcceptedBookingWithQuotation(ServicePaymentType::PayAfterService);
        $admin = Admin::factory()->create();

        $scheduled = app(ScheduleBookingAction::class)->handle(
            $admin,
            $booking->id,
            now()->addDay(),
            now()->addDay()->addHours(3),
        );

        $this->assertSame(BookingStatus::Scheduled, $scheduled->status);
    }

    public function test_only_accepted_bookings_can_be_scheduled(): void
    {
        [$booking] = $this->createAcceptedBookingWithQuotation(
            ServicePaymentType::PayAfterService,
            BookingStatus::Submitted,
        );
        $admin = Admin::factory()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only accepted bookings can be scheduled.');

        app(ScheduleBookingAction::class)->handle(
            $admin,
            $booking->id,
            now()->addDay(),
            now()->addDay()->addHours(3),
        );
    }

    public function test_completed_deposit_policy_booking_cannot_close_until_the_balance_is_confirmed(): void
    {
        [$booking, $quotation] = $this->createAcceptedBookingWithQuotation(
            ServicePaymentType::Deposit,
            BookingStatus::Completed,
        );
        $order = $this->createOrder($quotation);
        $this->createPaidPayment($order, PaymentStage::Deposit, '28.50');
        $admin = Admin::factory()->create();
        $close = app(CloseBookingAction::class);

        try {
            $close->handle($admin, $booking->id);
            self::fail('Closing should have been blocked before the balance payment.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'All required payments must be confirmed before the booking can be closed.',
                $exception->getMessage(),
            );
        }

        $this->createPaidPayment($order, PaymentStage::Balance, '66.50');

        $closed = $close->handle($admin, $booking->id);

        $this->assertSame(BookingStatus::Closed, $closed->status);
        $this->assertDatabaseHas('booking_status_histories', [
            'booking_id' => $booking->id,
            'status' => BookingStatus::Closed->value,
            'changed_by_type' => 'admin',
            'changed_by_id' => $admin->id,
        ]);
    }

    public function test_only_completed_bookings_can_be_closed(): void
    {
        [$booking] = $this->createAcceptedBookingWithQuotation(ServicePaymentType::PayAfterService);
        $admin = Admin::factory()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only completed bookings can be closed.');

        app(CloseBookingAction::class)->handle($admin, $booking->id);
    }

    public function test_confirming_the_final_balance_payment_closes_the_completed_booking(): void
    {
        [$booking, $quotation] = $this->createAcceptedBookingWithQuotation(
            ServicePaymentType::Deposit,
            BookingStatus::Completed,
        );
        $order = $this->createOrder($quotation);
        $this->createPaidPayment($order, PaymentStage::Deposit, '28.50');
        $balancePayment = $this->createPendingPayment($order, PaymentStage::Balance, '66.50');
        $admin = Admin::factory()->create();

        app(ConfirmOfflinePaymentAction::class)->handle($admin, $balancePayment->id);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => BookingStatus::Closed->value,
        ]);
        $this->assertDatabaseHas('payments', [
            'id' => $balancePayment->id,
            'status' => PaymentStatus::Paid->value,
        ]);
    }

    public function test_confirming_a_deposit_payment_does_not_close_the_booking(): void
    {
        [$booking, $quotation] = $this->createAcceptedBookingWithQuotation(
            ServicePaymentType::Deposit,
            BookingStatus::Completed,
        );
        $order = $this->createOrder($quotation);
        $depositPayment = $this->createPendingPayment($order, PaymentStage::Deposit, '28.50');
        $admin = Admin::factory()->create();

        app(ConfirmOfflinePaymentAction::class)->handle($admin, $depositPayment->id);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => BookingStatus::Completed->value,
        ]);
    }

    /**
     * @return array{0: Booking, 1: Quotation}
     */
    private function createAcceptedBookingWithQuotation(
        ServicePaymentType $paymentType,
        BookingStatus $bookingStatus = BookingStatus::Accepted,
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
            'address_snapshot' => [
                'line1' => 'KM4 Road',
                'city' => 'Mogadishu',
                'country_code' => 'SO',
            ],
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

    private function createPaidPayment(Order $order, PaymentStage $stage, string $amount): Payment
    {
        return Payment::query()->create([
            'payment_number' => sprintf('PAY-%s-%06d', now()->format('Y'), 800000 + $this->sequence++),
            'customer_profile_id' => $order->customer_profile_id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'status' => PaymentStatus::Paid,
            'payment_method' => 'evc_plus',
            'payment_stage' => $stage,
            'amount' => $amount,
            'currency' => 'USD',
            'paid_at' => now(),
            'receipt_number' => sprintf('RCPT-%s-%06d', now()->format('Y'), 800000 + $this->sequence),
        ]);
    }

    private function createPendingPayment(Order $order, PaymentStage $stage, string $amount): Payment
    {
        return Payment::query()->create([
            'payment_number' => sprintf('PAY-%s-%06d', now()->format('Y'), 800000 + $this->sequence++),
            'customer_profile_id' => $order->customer_profile_id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'status' => PaymentStatus::Pending,
            'payment_method' => 'evc_plus',
            'payment_stage' => $stage,
            'amount' => $amount,
            'currency' => 'USD',
        ]);
    }
}
