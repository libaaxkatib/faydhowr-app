<?php

namespace Tests\Feature\Api\V1\Order;

use App\Enums\QuotationStatus;
use App\Models\CustomerProfile;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateOrderTest extends TestCase
{
    use RefreshDatabase;

    private int $quotationSequence = 1;

    public function test_customer_can_create_an_order_from_an_accepted_quotation(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $quotation = $this->createQuotation($profile, QuotationStatus::Accepted);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/orders', [
                'quotation_id' => $quotation->id,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Order created successfully.')
            ->assertJsonPath('meta', null)
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.quotation.quotation_number', $quotation->quotation_number)
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.subtotal', '100.00')
            ->assertJsonPath('data.discount_amount', '10.00')
            ->assertJsonPath('data.tax_amount', '5.00')
            ->assertJsonPath('data.total_amount', '95.00')
            ->assertJsonPath('data.notes', 'Quotation notes.');

        self::assertMatchesRegularExpression(
            '/^ORD-'.now()->format('Y').'-\d{6}$/',
            $response->json('data.order_number'),
        );

        $this->assertDatabaseHas('orders', [
            'customer_profile_id' => $profile->id,
            'quotation_id' => $quotation->id,
            'status' => 'pending_payment',
            'currency' => 'USD',
            'subtotal' => 100,
            'discount_amount' => 10,
            'tax_amount' => 5,
            'total_amount' => 95,
            'notes' => 'Quotation notes.',
        ]);
        $this->assertDatabaseHas('order_status_histories', [
            'status' => 'pending_payment',
            'changed_by_type' => 'user',
            'changed_by_id' => $user->id,
        ]);
    }

    public function test_customer_cannot_create_an_order_from_another_customers_quotation(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);
        $quotation = $this->createQuotation(CustomerProfile::factory()->create(), QuotationStatus::Accepted);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/orders', [
                'quotation_id' => $quotation->id,
            ]);

        $response
            ->assertNotFound()
            ->assertJsonPath('error_code', 'QUOTATION_NOT_FOUND');
    }

    public function test_customer_cannot_create_an_order_from_a_non_accepted_quotation(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $quotation = $this->createQuotation($profile, QuotationStatus::Issued);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/orders', [
                'quotation_id' => $quotation->id,
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('message', 'The quotation must be accepted before creating an order.');
    }

    public function test_order_creation_returns_not_found_when_customer_profile_is_missing(): void
    {
        $user = User::factory()->create();
        $quotation = $this->createQuotation(CustomerProfile::factory()->create(), QuotationStatus::Accepted);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/orders', [
                'quotation_id' => $quotation->id,
            ]);

        $response
            ->assertNotFound()
            ->assertJsonPath('error_code', 'CUSTOMER_PROFILE_NOT_FOUND');
    }

    public function test_order_creation_returns_validation_errors(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/orders', []);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['quotation_id']]);
    }

    public function test_order_creation_requires_authentication(): void
    {
        $quotation = $this->createQuotation(CustomerProfile::factory()->create(), QuotationStatus::Accepted);

        $this
            ->postJson('/api/v1/orders', [
                'quotation_id' => $quotation->id,
            ])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_order_public_numbers_are_unique(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $firstQuotation = $this->createQuotation($profile, QuotationStatus::Accepted);
        $secondQuotation = $this->createQuotation($profile, QuotationStatus::Accepted);
        $token = $user->createToken('customer-mobile')->plainTextToken;

        $firstResponse = $this->withToken($token)->postJson('/api/v1/orders', [
            'quotation_id' => $firstQuotation->id,
        ]);
        $secondResponse = $this->withToken($token)->postJson('/api/v1/orders', [
            'quotation_id' => $secondQuotation->id,
        ]);

        $firstResponse->assertCreated();
        $secondResponse->assertCreated();
        self::assertNotSame(
            $firstResponse->json('data.order_number'),
            $secondResponse->json('data.order_number'),
        );
    }

    private function createQuotation(
        CustomerProfile $profile,
        QuotationStatus $status,
    ): Quotation {
        return Quotation::query()->create([
            'quotation_number' => sprintf('QT-%s-%06d', now()->format('Y'), $this->quotationSequence++),
            'customer_profile_id' => $profile->id,
            'status' => $status,
            'currency' => 'USD',
            'subtotal' => '100.00',
            'discount_amount' => '10.00',
            'tax_amount' => '5.00',
            'total_amount' => '95.00',
            'valid_until' => now()->addWeek(),
            'accepted_at' => $status === QuotationStatus::Accepted ? now() : null,
            'notes' => 'Quotation notes.',
        ]);
    }
}
