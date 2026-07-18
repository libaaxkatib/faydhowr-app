<?php

namespace Tests\Feature\Api\V1\Quotation;

use App\Enums\BookingStatus;
use App\Enums\ServiceMode;
use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceModeOption;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateQuotationTest extends TestCase
{
    use RefreshDatabase;

    private int $bookingSequence = 1;

    public function test_customer_can_create_a_draft_quotation_without_a_booking(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/quotations', $this->payload());

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Quotation created successfully.')
            ->assertJsonPath('meta', null)
            ->assertJsonPath('data.booking', null)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.latest_version', null)
            ->assertJsonPath('data.can_accept', false)
            ->assertJsonPath('data.can_discuss', false)
            ->assertJsonPath('data.requirements', 'Full villa deep cleaning.')
            ->assertJsonPath('data.total_amount', '0.00');

        self::assertMatchesRegularExpression(
            '/^QT-'.now()->format('Y').'-\d{6}$/',
            $response->json('data.quotation_number'),
        );

        $this->assertDatabaseHas('quotations', [
            'customer_profile_id' => $profile->id,
            'booking_id' => null,
            'status' => 'draft',
            'requirements' => 'Full villa deep cleaning.',
            'subtotal' => 0,
            'total_amount' => 0,
        ]);
        $this->assertDatabaseHas('quotation_status_histories', [
            'status' => 'draft',
            'changed_by_type' => 'user',
            'changed_by_id' => $user->id,
        ]);
    }

    public function test_customer_can_create_a_draft_quotation_for_an_owned_booking(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $booking = $this->createBooking($profile);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/quotations', $this->payload(['booking_id' => $booking->id]));

        $response
            ->assertCreated()
            ->assertJsonPath('data.booking.booking_number', $booking->booking_number);

        $this->assertDatabaseHas('quotations', [
            'customer_profile_id' => $profile->id,
            'booking_id' => $booking->id,
        ]);
    }

    public function test_customer_can_create_a_draft_quotation_with_staged_uploads(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $upload = Upload::factory()->create([
            'customer_profile_id' => $profile->id,
            'original_name' => 'site-photo.jpg',
        ]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/quotations', $this->payload([
                'attachment_ids' => [$upload->uuid],
            ]));

        $response
            ->assertCreated()
            ->assertJsonPath('data.attachments.0.uuid', $upload->uuid)
            ->assertJsonPath('data.attachments.0.original_name', 'site-photo.jpg');

        $this->assertDatabaseHas('quotation_attachments', [
            'upload_id' => $upload->id,
        ]);
        self::assertNotNull($upload->refresh()->attached_at);
    }

    public function test_quotation_creation_rejects_uploads_owned_by_another_customer(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);
        $foreignUpload = Upload::factory()->create([
            'customer_profile_id' => CustomerProfile::factory()->create()->id,
        ]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/quotations', $this->payload([
                'attachment_ids' => [$foreignUpload->uuid],
            ]));

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');

        $this->assertDatabaseCount('quotations', 0);
    }

    public function test_quotation_creation_rejects_expired_uploads(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $expiredUpload = Upload::factory()->expired()->create([
            'customer_profile_id' => $profile->id,
        ]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/quotations', $this->payload([
                'attachment_ids' => [$expiredUpload->uuid],
            ]));

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_customer_cannot_create_a_quotation_for_another_customers_booking(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);
        $otherBooking = $this->createBooking(CustomerProfile::factory()->create());

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/quotations', $this->payload(['booking_id' => $otherBooking->id]));

        $response
            ->assertNotFound()
            ->assertJsonPath('error_code', 'BOOKING_NOT_FOUND');
    }

    public function test_quotation_creation_returns_not_found_when_customer_profile_is_missing(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/quotations', $this->payload());

        $response
            ->assertNotFound()
            ->assertJsonPath('error_code', 'CUSTOMER_PROFILE_NOT_FOUND');
    }

    public function test_quotation_creation_requires_the_request_details(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/quotations', []);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['requirements']]);
    }

    public function test_quotation_creation_prohibits_customer_pricing_fields(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/quotations', $this->payload([
                'subtotal' => '100.00',
                'discount_amount' => '10.00',
                'tax_amount' => '5.00',
                'total_amount' => '95.00',
                'payment_type' => 'deposit',
                'deposit_amount' => '30.00',
                'remaining_amount' => '65.00',
            ]));

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => [
                'subtotal',
                'discount_amount',
                'tax_amount',
                'total_amount',
                'payment_type',
                'deposit_amount',
                'remaining_amount',
            ]]);

        $this->assertDatabaseCount('quotations', 0);
    }

    public function test_quotation_creation_requires_authentication(): void
    {
        $this
            ->postJson('/api/v1/quotations', $this->payload())
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_quotation_public_numbers_are_unique(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);
        $token = $user->createToken('customer-mobile')->plainTextToken;

        $firstResponse = $this->withToken($token)->postJson('/api/v1/quotations', $this->payload());
        $secondResponse = $this->withToken($token)->postJson('/api/v1/quotations', $this->payload());

        $firstResponse->assertCreated();
        $secondResponse->assertCreated();
        self::assertNotSame(
            $firstResponse->json('data.quotation_number'),
            $secondResponse->json('data.quotation_number'),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'requirements' => 'Full villa deep cleaning.',
            'description' => 'Three floors, garden included.',
            'preferred_timing' => 'Weekday mornings',
            'quantity_hint' => 3,
            ...$overrides,
        ];
    }

    private function createBooking(?CustomerProfile $profile = null): Booking
    {
        $profile ??= CustomerProfile::factory()->create();
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
