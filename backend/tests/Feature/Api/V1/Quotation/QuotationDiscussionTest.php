<?php

namespace Tests\Feature\Api\V1\Quotation;

use App\Enums\QuotationStatus;
use App\Models\CustomerProfile;
use App\Models\Quotation;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationDiscussionTest extends TestCase
{
    use RefreshDatabase;

    private int $quotationSequence = 1;

    public function test_customer_can_list_an_owned_quotation_discussion(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $quotation = $this->createQuotation($profile, QuotationStatus::UnderDiscussion);
        $quotation->discussionMessages()->create([
            'sender_type' => 'user',
            'sender_id' => $user->id,
            'message' => 'First message.',
        ]);
        $quotation->discussionMessages()->create([
            'sender_type' => 'user',
            'sender_id' => $user->id,
            'message' => 'Second message.',
        ]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson("/api/v1/quotations/{$quotation->id}/discussion");

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Quotation discussion retrieved successfully.')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.message', 'First message.')
            ->assertJsonPath('data.1.message', 'Second message.');
    }

    public function test_customer_can_post_a_message_and_first_message_changes_status(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $quotation = $this->createQuotation($profile, QuotationStatus::QuotationReady);
        $upload = Upload::factory()->create([
            'customer_profile_id' => $profile->id,
            'original_name' => 'reference.jpg',
        ]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson("/api/v1/quotations/{$quotation->id}/discussion", [
                'message' => 'Please revise the scope.',
                'upload_ids' => [$upload->uuid],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Quotation discussion message created successfully.')
            ->assertJsonPath('data.sender_type', 'user')
            ->assertJsonPath('data.message', 'Please revise the scope.')
            ->assertJsonPath('data.attachments.0.uuid', $upload->uuid)
            ->assertJsonPath('data.attachments.0.original_name', 'reference.jpg');

        $this->assertDatabaseHas('quotation_discussion_messages', [
            'quotation_id' => $quotation->id,
            'sender_type' => 'user',
            'sender_id' => $user->id,
            'message' => 'Please revise the scope.',
        ]);
        $this->assertDatabaseHas('quotation_message_attachments', [
            'upload_id' => $upload->id,
        ]);
        self::assertNotNull($upload->refresh()->attached_at);
        $this->assertDatabaseHas('quotations', [
            'id' => $quotation->id,
            'status' => 'under_discussion',
        ]);
        $this->assertDatabaseHas('quotation_status_histories', [
            'quotation_id' => $quotation->id,
            'status' => 'under_discussion',
            'changed_by_type' => 'user',
            'changed_by_id' => $user->id,
        ]);
    }

    public function test_message_on_an_under_discussion_quotation_keeps_its_status(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $quotation = $this->createQuotation($profile, QuotationStatus::UnderDiscussion);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson("/api/v1/quotations/{$quotation->id}/discussion", [
                'message' => 'Thank you for the update.',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.message', 'Thank you for the update.');

        $this->assertDatabaseHas('quotations', [
            'id' => $quotation->id,
            'status' => 'under_discussion',
        ]);
        $this->assertDatabaseMissing('quotation_status_histories', [
            'quotation_id' => $quotation->id,
        ]);
    }

    public function test_discussion_is_rejected_for_an_invalid_quotation_status(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $quotation = $this->createQuotation($profile, QuotationStatus::Submitted);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson("/api/v1/quotations/{$quotation->id}/discussion", [
                'message' => 'This should not be accepted.',
            ]);

        $response
            ->assertConflict()
            ->assertJsonPath('error_code', 'QUOTATION_INVALID_STATE')
            ->assertJsonPath('message', 'Discussion is not available for this quotation.');
    }

    public function test_non_owned_quotation_discussion_returns_not_found(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);
        $quotation = $this->createQuotation(CustomerProfile::factory()->create(), QuotationStatus::QuotationReady);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson("/api/v1/quotations/{$quotation->id}/discussion");

        $response
            ->assertNotFound()
            ->assertJsonPath('error_code', 'QUOTATION_NOT_FOUND');
    }

    public function test_posting_to_a_non_owned_quotation_discussion_returns_not_found(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);
        $quotation = $this->createQuotation(CustomerProfile::factory()->create(), QuotationStatus::QuotationReady);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson("/api/v1/quotations/{$quotation->id}/discussion", [
                'message' => 'Unauthorized message.',
            ]);

        $response
            ->assertNotFound()
            ->assertJsonPath('error_code', 'QUOTATION_NOT_FOUND');
    }

    public function test_discussion_returns_not_found_when_customer_profile_is_missing(): void
    {
        $user = User::factory()->create();
        $quotation = $this->createQuotation(CustomerProfile::factory()->create(), QuotationStatus::QuotationReady);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson("/api/v1/quotations/{$quotation->id}/discussion");

        $response
            ->assertNotFound()
            ->assertJsonPath('error_code', 'CUSTOMER_PROFILE_NOT_FOUND');
    }

    public function test_discussion_requires_authentication(): void
    {
        $quotation = $this->createQuotation(CustomerProfile::factory()->create(), QuotationStatus::QuotationReady);

        $this
            ->postJson("/api/v1/quotations/{$quotation->id}/discussion", [
                'message' => 'Unauthenticated message.',
            ])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    private function createQuotation(
        CustomerProfile $profile,
        QuotationStatus $status,
    ): Quotation {
        return Quotation::query()->create([
            'quotation_number' => sprintf('QT-%s-%06d', now()->format('Y'), $this->quotationSequence++),
            'customer_profile_id' => $profile->id,
            'status' => $status,
            'subtotal' => '100.00',
            'discount_amount' => '10.00',
            'tax_amount' => '5.00',
            'total_amount' => '95.00',
            'valid_until' => now()->addWeek(),
        ]);
    }
}
