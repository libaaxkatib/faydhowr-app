<?php

namespace Tests\Feature\Api\V1\Quotation;

use App\Enums\QuotationRevisionSource;
use App\Enums\QuotationStatus;
use App\Models\CustomerProfile;
use App\Models\Quotation;
use App\Models\QuotationRevision;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private int $quotationSequence = 1;

    public function test_customer_can_update_a_draft_quotation(): void
    {
        [$user, $profile, $token] = $this->createCustomer();
        $quotation = $this->createQuotation($profile, QuotationStatus::Draft);

        $this->withToken($token)
            ->patchJson("/api/v1/quotations/{$quotation->id}", [
                'requirements' => 'Updated requirements.',
                'quantity_hint' => 5,
            ])
            ->assertOk()
            ->assertJsonPath('data.requirements', 'Updated requirements.')
            ->assertJsonPath('data.quantity_hint', 5);

        $this->assertDatabaseHas('quotations', [
            'id' => $quotation->id,
            'requirements' => 'Updated requirements.',
            'quantity_hint' => 5,
        ]);
    }

    public function test_customer_cannot_update_a_submitted_quotation(): void
    {
        [$user, $profile, $token] = $this->createCustomer();
        $quotation = $this->createQuotation($profile, QuotationStatus::Submitted);

        $this->withToken($token)
            ->patchJson("/api/v1/quotations/{$quotation->id}", [
                'requirements' => 'Too late.',
            ])
            ->assertConflict()
            ->assertJsonPath('error_code', 'QUOTATION_NOT_EDITABLE');
    }

    public function test_draft_update_prohibits_pricing_fields(): void
    {
        [$user, $profile, $token] = $this->createCustomer();
        $quotation = $this->createQuotation($profile, QuotationStatus::Draft);

        $this->withToken($token)
            ->patchJson("/api/v1/quotations/{$quotation->id}", [
                'subtotal' => '100.00',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['subtotal']]);
    }

    public function test_customer_can_submit_a_draft_quotation(): void
    {
        [$user, $profile, $token] = $this->createCustomer();
        $quotation = $this->createQuotation($profile, QuotationStatus::Draft);

        $this->withToken($token)
            ->postJson("/api/v1/quotations/{$quotation->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $this->assertDatabaseHas('quotations', [
            'id' => $quotation->id,
            'status' => 'submitted',
        ]);
        self::assertNotNull($quotation->refresh()->submitted_at);
        $this->assertDatabaseHas('quotation_status_histories', [
            'quotation_id' => $quotation->id,
            'status' => 'submitted',
            'changed_by_type' => 'user',
            'changed_by_id' => $user->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'title' => 'Quotation Request Submitted',
        ]);
    }

    public function test_submitting_a_non_draft_quotation_returns_conflict(): void
    {
        [$user, $profile, $token] = $this->createCustomer();
        $quotation = $this->createQuotation($profile, QuotationStatus::Submitted);

        $this->withToken($token)
            ->postJson("/api/v1/quotations/{$quotation->id}/submit")
            ->assertConflict()
            ->assertJsonPath('error_code', 'QUOTATION_INVALID_STATE');
    }

    public function test_customer_can_cancel_a_draft_or_submitted_quotation(): void
    {
        [$user, $profile, $token] = $this->createCustomer();
        $draft = $this->createQuotation($profile, QuotationStatus::Draft);
        $submitted = $this->createQuotation($profile, QuotationStatus::Submitted);

        $this->withToken($token)
            ->postJson("/api/v1/quotations/{$draft->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->withToken($token)
            ->postJson("/api/v1/quotations/{$submitted->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('notifications', [
            'title' => 'Quotation Cancelled',
        ]);
    }

    public function test_customer_cannot_cancel_a_priced_quotation(): void
    {
        [$user, $profile, $token] = $this->createCustomer();
        $quotation = $this->createQuotation($profile, QuotationStatus::QuotationReady);

        $this->withToken($token)
            ->postJson("/api/v1/quotations/{$quotation->id}/cancel")
            ->assertConflict()
            ->assertJsonPath('error_code', 'QUOTATION_INVALID_STATE');
    }

    public function test_customer_can_attach_uploads_while_draft(): void
    {
        [$user, $profile, $token] = $this->createCustomer();
        $quotation = $this->createQuotation($profile, QuotationStatus::Draft);
        $upload = Upload::factory()->create(['customer_profile_id' => $profile->id]);

        $this->withToken($token)
            ->postJson("/api/v1/quotations/{$quotation->id}/attachments", [
                'upload_ids' => [$upload->uuid],
            ])
            ->assertCreated()
            ->assertJsonPath('data.attachments.0.uuid', $upload->uuid);

        self::assertNotNull($upload->refresh()->attached_at);
    }

    public function test_attachments_are_immutable_after_submit(): void
    {
        [$user, $profile, $token] = $this->createCustomer();
        $quotation = $this->createQuotation($profile, QuotationStatus::Submitted);
        $upload = Upload::factory()->create(['customer_profile_id' => $profile->id]);

        $this->withToken($token)
            ->postJson("/api/v1/quotations/{$quotation->id}/attachments", [
                'upload_ids' => [$upload->uuid],
            ])
            ->assertConflict()
            ->assertJsonPath('error_code', 'QUOTATION_ATTACHMENTS_LOCKED');
    }

    public function test_customer_can_detach_an_attachment_while_draft(): void
    {
        [$user, $profile, $token] = $this->createCustomer();
        $quotation = $this->createQuotation($profile, QuotationStatus::Draft);
        $upload = Upload::factory()->attached()->create(['customer_profile_id' => $profile->id]);
        $attachment = $quotation->attachments()->create(['upload_id' => $upload->id]);

        $this->withToken($token)
            ->deleteJson("/api/v1/quotations/{$quotation->id}/attachments/{$attachment->id}")
            ->assertOk();

        $this->assertDatabaseMissing('quotation_attachments', ['id' => $attachment->id]);
        self::assertNull($upload->refresh()->attached_at);
    }

    public function test_detaching_after_submit_returns_conflict(): void
    {
        [$user, $profile, $token] = $this->createCustomer();
        $quotation = $this->createQuotation($profile, QuotationStatus::Submitted);
        $upload = Upload::factory()->attached()->create(['customer_profile_id' => $profile->id]);
        $attachment = $quotation->attachments()->create(['upload_id' => $upload->id]);

        $this->withToken($token)
            ->deleteJson("/api/v1/quotations/{$quotation->id}/attachments/{$attachment->id}")
            ->assertConflict()
            ->assertJsonPath('error_code', 'QUOTATION_ATTACHMENTS_LOCKED');
    }

    public function test_an_upload_cannot_be_attached_twice(): void
    {
        [$user, $profile, $token] = $this->createCustomer();
        $quotation = $this->createQuotation($profile, QuotationStatus::Draft);
        $upload = Upload::factory()->attached()->create(['customer_profile_id' => $profile->id]);

        $this->withToken($token)
            ->postJson("/api/v1/quotations/{$quotation->id}/attachments", [
                'upload_ids' => [$upload->uuid],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_customer_can_list_quotation_revisions(): void
    {
        [$user, $profile, $token] = $this->createCustomer();
        $quotation = $this->createQuotation($profile, QuotationStatus::QuotationReady);
        $this->createRevision($quotation, 1);
        $latest = $this->createRevision($quotation, 2);

        $this->withToken($token)
            ->getJson("/api/v1/quotations/{$quotation->id}/revisions")
            ->assertOk()
            ->assertJsonPath('data.quotation_number', $quotation->quotation_number)
            ->assertJsonPath('data.latest_version', 2)
            ->assertJsonCount(2, 'data.revisions')
            ->assertJsonPath('data.revisions.0.version_number', 2)
            ->assertJsonPath('data.revisions.0.is_latest', true)
            ->assertJsonPath('data.revisions.1.version_number', 1)
            ->assertJsonPath('data.revisions.1.is_latest', false);

        self::assertSame($latest->id, $quotation->refresh()->latest_revision_id);
    }

    public function test_customer_can_view_the_quotation_timeline(): void
    {
        [$user, $profile, $token] = $this->createCustomer();
        $quotation = $this->createQuotation($profile, QuotationStatus::Submitted);
        $quotation->statusHistories()->create([
            'status' => QuotationStatus::Draft,
            'changed_by_type' => 'user',
            'changed_by_id' => $user->id,
        ]);
        $quotation->statusHistories()->create([
            'status' => QuotationStatus::Submitted,
            'changed_by_type' => 'user',
            'changed_by_id' => $user->id,
        ]);

        $this->withToken($token)
            ->getJson("/api/v1/quotations/{$quotation->id}/timeline")
            ->assertOk()
            ->assertJsonPath('data.quotation_number', $quotation->quotation_number)
            ->assertJsonCount(2, 'data.events')
            ->assertJsonPath('data.events.0.status', 'draft')
            ->assertJsonPath('data.events.1.status', 'submitted');
    }

    public function test_acceptance_requires_a_revision_reference_when_revisions_exist(): void
    {
        [$user, $profile, $token] = $this->createCustomer();
        $quotation = $this->createQuotation($profile, QuotationStatus::QuotationReady);
        $this->createRevision($quotation, 1);

        $this->withToken($token)
            ->postJson("/api/v1/quotations/{$quotation->id}/accept")
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('message', 'The revision reference is required to accept this quotation.');
    }

    public function test_accepting_a_stale_revision_returns_conflict(): void
    {
        [$user, $profile, $token] = $this->createCustomer();
        $quotation = $this->createQuotation($profile, QuotationStatus::QuotationReady);
        $staleRevision = $this->createRevision($quotation, 1);
        $this->createRevision($quotation, 2);

        $this->withToken($token)
            ->postJson("/api/v1/quotations/{$quotation->id}/accept", [
                'revision_id' => $staleRevision->id,
            ])
            ->assertConflict()
            ->assertJsonPath('error_code', 'QUOTATION_REVISION_STALE');

        $this->assertDatabaseHas('quotations', [
            'id' => $quotation->id,
            'status' => 'quotation_ready',
        ]);
    }

    public function test_accepting_a_stale_version_number_returns_conflict(): void
    {
        [$user, $profile, $token] = $this->createCustomer();
        $quotation = $this->createQuotation($profile, QuotationStatus::QuotationReady);
        $this->createRevision($quotation, 1);
        $this->createRevision($quotation, 2);

        $this->withToken($token)
            ->postJson("/api/v1/quotations/{$quotation->id}/accept", [
                'version_number' => 1,
            ])
            ->assertConflict()
            ->assertJsonPath('error_code', 'QUOTATION_REVISION_STALE');
    }

    public function test_customer_can_accept_the_latest_revision(): void
    {
        [$user, $profile, $token] = $this->createCustomer();
        $quotation = $this->createQuotation($profile, QuotationStatus::QuotationReady);
        $this->createRevision($quotation, 1);
        $latest = $this->createRevision($quotation, 2);

        $this->withToken($token)
            ->postJson("/api/v1/quotations/{$quotation->id}/accept", [
                'revision_id' => $latest->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $this->assertDatabaseHas('quotations', [
            'id' => $quotation->id,
            'status' => 'accepted',
        ]);
    }

    public function test_accepting_an_expired_latest_revision_returns_conflict(): void
    {
        [$user, $profile, $token] = $this->createCustomer();
        $quotation = $this->createQuotation($profile, QuotationStatus::QuotationReady);
        $expiredRevision = $this->createRevision($quotation, 1, validUntil: now()->subDay());

        $this->withToken($token)
            ->postJson("/api/v1/quotations/{$quotation->id}/accept", [
                'revision_id' => $expiredRevision->id,
            ])
            ->assertConflict()
            ->assertJsonPath('error_code', 'QUOTATION_INVALID_STATE');
    }

    public function test_quotation_resource_exposes_server_calculated_flags(): void
    {
        [$user, $profile, $token] = $this->createCustomer();
        $quotation = $this->createQuotation($profile, QuotationStatus::QuotationReady);
        $this->createRevision($quotation, 1);

        $this->withToken($token)
            ->getJson("/api/v1/quotations/{$quotation->id}")
            ->assertOk()
            ->assertJsonPath('data.quotation_number', $quotation->quotation_number)
            ->assertJsonPath('data.latest_version', 1)
            ->assertJsonPath('data.status', 'quotation_ready')
            ->assertJsonPath('data.can_accept', true)
            ->assertJsonPath('data.can_discuss', true);
    }

    public function test_flags_are_false_once_the_latest_revision_has_expired(): void
    {
        [$user, $profile, $token] = $this->createCustomer();
        $quotation = $this->createQuotation($profile, QuotationStatus::QuotationReady);
        $this->createRevision($quotation, 1, validUntil: now()->subDay());

        $this->withToken($token)
            ->getJson("/api/v1/quotations/{$quotation->id}")
            ->assertOk()
            ->assertJsonPath('data.can_accept', false)
            ->assertJsonPath('data.can_discuss', true);
    }

    public function test_legacy_quotations_are_backfilled_into_version_one(): void
    {
        $profile = CustomerProfile::factory()->create();
        $legacy = $this->createQuotation($profile, QuotationStatus::QuotationReady, [
            'subtotal' => '100.00',
            'discount_amount' => '10.00',
            'tax_amount' => '5.00',
            'total_amount' => '95.00',
            'valid_until' => now()->addWeek(),
        ]);

        $migration = include base_path('database/migrations/2026_07_18_190400_backfill_legacy_quotation_revisions.php');
        $migration->up();

        $legacy->refresh();
        $revision = QuotationRevision::query()->where('quotation_id', $legacy->id)->sole();

        self::assertSame(1, $revision->version_number);
        self::assertSame(QuotationRevisionSource::SystemMigration, $revision->source);
        self::assertSame('95.00', (string) $revision->total_amount);
        self::assertSame($revision->id, $legacy->latest_revision_id);
    }

    /**
     * @return array{0: User, 1: CustomerProfile, 2: string}
     */
    private function createCustomer(): array
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);

        return [$user, $profile, $user->createToken('customer-mobile')->plainTextToken];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createQuotation(
        CustomerProfile $profile,
        QuotationStatus $status,
        array $overrides = [],
    ): Quotation {
        return Quotation::query()->create([
            'quotation_number' => sprintf('QT-%s-%06d', now()->format('Y'), $this->quotationSequence++),
            'customer_profile_id' => $profile->id,
            'status' => $status,
            'requirements' => 'Full villa deep cleaning.',
            'subtotal' => '0.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total_amount' => '0.00',
            ...$overrides,
        ]);
    }

    private function createRevision(
        Quotation $quotation,
        int $versionNumber,
        mixed $validUntil = null,
    ): QuotationRevision {
        $revision = $quotation->revisions()->create([
            'version_number' => $versionNumber,
            'source' => QuotationRevisionSource::AdminIssue,
            'subtotal_amount' => '100.00',
            'discount_amount' => '10.00',
            'tax_amount' => '5.00',
            'total_amount' => '95.00',
            'valid_until' => $validUntil ?? now()->addWeek(),
        ]);

        $quotation->forceFill([
            'latest_revision_id' => $revision->id,
            'subtotal' => '100.00',
            'discount_amount' => '10.00',
            'tax_amount' => '5.00',
            'total_amount' => '95.00',
            'valid_until' => $revision->valid_until,
        ])->save();

        return $revision;
    }
}
