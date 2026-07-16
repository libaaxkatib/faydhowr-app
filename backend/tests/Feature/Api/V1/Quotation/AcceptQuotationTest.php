<?php

namespace Tests\Feature\Api\V1\Quotation;

use App\Enums\BookingStatus;
use App\Enums\QuotationStatus;
use App\Enums\ServiceMode;
use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\Quotation;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceModeOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcceptQuotationTest extends TestCase
{
    use RefreshDatabase;

    private int $bookingSequence = 1;

    private int $quotationSequence = 1;

    public function test_customer_can_accept_a_quotation_ready_quotation_without_changing_its_booking(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $booking = $this->createBooking($profile, BookingStatus::Submitted);
        $quotation = $this->createQuotation($profile, $booking, QuotationStatus::QuotationReady);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson("/api/v1/quotations/{$quotation->id}/accept");

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Quotation accepted successfully.')
            ->assertJsonPath('meta', null)
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.booking.booking_number', $booking->booking_number);

        self::assertNotNull($response->json('data.accepted_at'));
        $this->assertDatabaseHas('quotations', [
            'id' => $quotation->id,
            'status' => 'accepted',
        ]);
        $this->assertDatabaseHas('quotation_status_histories', [
            'quotation_id' => $quotation->id,
            'status' => 'accepted',
            'changed_by_type' => 'user',
            'changed_by_id' => $user->id,
        ]);
        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'submitted',
        ]);
    }

    public function test_customer_can_accept_an_under_discussion_quotation(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $quotation = $this->createQuotation($profile, null, QuotationStatus::UnderDiscussion);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson("/api/v1/quotations/{$quotation->id}/accept");

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');
    }

    public function test_quotation_acceptance_rejects_invalid_statuses(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $quotation = $this->createQuotation($profile, null, QuotationStatus::PendingReview);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson("/api/v1/quotations/{$quotation->id}/accept");

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('message', 'This quotation cannot be accepted.');
    }

    public function test_non_owned_quotation_acceptance_returns_not_found(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);
        $quotation = $this->createQuotation(CustomerProfile::factory()->create(), null, QuotationStatus::QuotationReady);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson("/api/v1/quotations/{$quotation->id}/accept");

        $response
            ->assertNotFound()
            ->assertJsonPath('error_code', 'QUOTATION_NOT_FOUND');
    }

    public function test_acceptance_returns_not_found_when_customer_profile_is_missing(): void
    {
        $user = User::factory()->create();
        $quotation = $this->createQuotation(CustomerProfile::factory()->create(), null, QuotationStatus::QuotationReady);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson("/api/v1/quotations/{$quotation->id}/accept");

        $response
            ->assertNotFound()
            ->assertJsonPath('error_code', 'CUSTOMER_PROFILE_NOT_FOUND');
    }

    public function test_acceptance_requires_authentication(): void
    {
        $quotation = $this->createQuotation(CustomerProfile::factory()->create(), null, QuotationStatus::QuotationReady);

        $this
            ->postJson("/api/v1/quotations/{$quotation->id}/accept")
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    private function createQuotation(
        CustomerProfile $profile,
        ?Booking $booking,
        QuotationStatus $status,
    ): Quotation {
        return Quotation::query()->create([
            'quotation_number' => sprintf('QT-%s-%06d', now()->format('Y'), $this->quotationSequence++),
            'customer_profile_id' => $profile->id,
            'booking_id' => $booking?->id,
            'status' => $status,
            'subtotal' => '100.00',
            'discount_amount' => '10.00',
            'tax_amount' => '5.00',
            'total_amount' => '95.00',
            'valid_until' => now()->addWeek(),
        ]);
    }

    private function createBooking(CustomerProfile $profile, BookingStatus $status): Booking
    {
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
        ]);
        $mode = ServiceModeOption::query()->create([
            'service_id' => $service->id,
            'mode' => ServiceMode::OneTime,
            'is_active' => true,
        ]);

        return Booking::query()->create([
            'booking_number' => sprintf('BK-%s-%06d', now()->format('Y'), $this->bookingSequence++),
            'customer_profile_id' => $profile->id,
            'service_id' => $service->id,
            'service_mode_id' => $mode->id,
            'status' => $status,
            'requested_date' => now()->addWeek()->toDateString(),
            'requested_time_window' => '09:00-12:00',
            'address_snapshot' => [
                'line1' => 'KM4 Road',
                'city' => 'Mogadishu',
                'country_code' => 'SO',
            ],
        ]);
    }
}
