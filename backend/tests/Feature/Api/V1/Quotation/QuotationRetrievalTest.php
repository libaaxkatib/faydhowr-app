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

class QuotationRetrievalTest extends TestCase
{
    use RefreshDatabase;

    private int $bookingSequence = 1;

    private int $quotationSequence = 1;

    public function test_customer_can_list_only_their_quotations_newest_first(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $olderQuotation = $this->createQuotation($profile, null, QuotationStatus::Submitted, now()->subDay());
        $newerQuotation = $this->createQuotation($profile, null, QuotationStatus::QuotationReady, now());
        $this->createQuotation(CustomerProfile::factory()->create());

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/quotations');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Quotations retrieved successfully.')
            ->assertJsonPath('meta', null)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.quotation_number', $newerQuotation->quotation_number)
            ->assertJsonPath('data.items.1.quotation_number', $olderQuotation->quotation_number);
    }

    public function test_customer_quotations_are_paginated(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);

        $this->createQuotation($profile);
        $this->createQuotation($profile);
        $this->createQuotation($profile);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/quotations?per_page=2&page=2');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.pagination.current_page', 2)
            ->assertJsonPath('data.pagination.per_page', 2)
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.pagination.last_page', 2);
    }

    public function test_customer_can_filter_quotations_by_status(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $this->createQuotation($profile, null, QuotationStatus::Submitted);
        $issuedQuotation = $this->createQuotation($profile, null, QuotationStatus::QuotationReady);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/quotations?status=quotation_ready');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quotation_number', $issuedQuotation->quotation_number)
            ->assertJsonPath('data.items.0.status', 'quotation_ready');
    }

    public function test_customer_can_filter_quotations_by_booking(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $booking = $this->createBooking($profile);
        $matchingQuotation = $this->createQuotation($profile, $booking);
        $this->createQuotation($profile);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson("/api/v1/quotations?booking_id={$booking->id}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quotation_number', $matchingQuotation->quotation_number)
            ->assertJsonPath('data.items.0.booking.booking_number', $booking->booking_number);
    }

    public function test_quotation_list_rejects_an_invalid_status_filter(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/quotations?status=invalid');

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['status']]);
    }

    public function test_quotation_list_rejects_a_non_integer_booking_filter(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/quotations?booking_id=invalid');

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['booking_id']]);
    }

    public function test_customer_can_view_an_owned_quotation(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $booking = $this->createBooking($profile);
        $quotation = $this->createQuotation($profile, $booking, QuotationStatus::QuotationReady);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson("/api/v1/quotations/{$quotation->id}");

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Quotation retrieved successfully.')
            ->assertJsonPath('data.quotation_number', $quotation->quotation_number)
            ->assertJsonPath('data.status', 'quotation_ready')
            ->assertJsonPath('data.booking.booking_number', $booking->booking_number)
            ->assertJsonPath('data.subtotal', '100.00')
            ->assertJsonPath('data.discount_amount', '10.00')
            ->assertJsonPath('data.tax_amount', '5.00')
            ->assertJsonPath('data.total_amount', '95.00')
            ->assertJsonPath('data.notes', 'Quotation notes.')
            ->assertJsonPath('data.created_at', $quotation->created_at->toISOString())
            ->assertJsonMissingPath('data.customer_profile_id')
            ->assertJsonMissingPath('data.changed_by_id');
    }

    public function test_other_customers_quotation_returns_not_found(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);
        $otherQuotation = $this->createQuotation(CustomerProfile::factory()->create());

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson("/api/v1/quotations/{$otherQuotation->id}");

        $response
            ->assertNotFound()
            ->assertJsonPath('error_code', 'QUOTATION_NOT_FOUND');
    }

    public function test_quotation_retrieval_returns_not_found_when_customer_profile_is_missing(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/quotations');

        $response
            ->assertNotFound()
            ->assertJsonPath('error_code', 'CUSTOMER_PROFILE_NOT_FOUND');
    }

    public function test_quotation_retrieval_requires_authentication(): void
    {
        $this
            ->getJson('/api/v1/quotations')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    private function createQuotation(
        CustomerProfile $profile,
        ?Booking $booking = null,
        QuotationStatus $status = QuotationStatus::Submitted,
        mixed $createdAt = null,
    ): Quotation {
        $quotation = Quotation::query()->create([
            'quotation_number' => sprintf('QT-%s-%06d', now()->format('Y'), $this->quotationSequence++),
            'customer_profile_id' => $profile->id,
            'booking_id' => $booking?->id,
            'status' => $status,
            'subtotal' => '100.00',
            'discount_amount' => '10.00',
            'tax_amount' => '5.00',
            'total_amount' => '95.00',
            'valid_until' => now()->addWeek(),
            'notes' => 'Quotation notes.',
        ]);

        if ($createdAt !== null) {
            $quotation->forceFill(['created_at' => $createdAt])->save();
        }

        return $quotation->refresh();
    }

    private function createBooking(CustomerProfile $profile): Booking
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
            'status' => BookingStatus::Submitted,
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
