<?php

namespace Tests\Feature\Api\V1\Admin\Quotations;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Enums\BookingStatus;
use App\Enums\QuotationRevisionSource;
use App\Enums\QuotationStatus;
use App\Enums\ServiceMode;
use App\Models\Admin;
use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\Quotation;
use App\Models\QuotationRevision;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceModeOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminQuotationsApiTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

    public function test_listing_quotations_requires_the_quotations_view_permission(): void
    {
        [, $token] = $this->adminWithPermissions([]);

        $this->withToken($token)
            ->getJson('/api/v1/admin/quotations')
            ->assertForbidden();
    }

    public function test_admin_can_list_quotations_with_a_status_filter(): void
    {
        $submitted = $this->createQuotation(status: QuotationStatus::Submitted);
        $this->createQuotation(status: QuotationStatus::Draft);

        [, $token] = $this->adminWithPermissions([AdminPermission::QuotationsView]);

        $this->withToken($token)
            ->getJson('/api/v1/admin/quotations?status=submitted')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.items.0.quotation_number', $submitted->quotation_number);
    }

    public function test_admin_can_search_quotations_by_number(): void
    {
        $quotation = $this->createQuotation(status: QuotationStatus::Submitted);
        $this->createQuotation(status: QuotationStatus::Submitted);

        [, $token] = $this->adminWithPermissions([AdminPermission::QuotationsView]);

        $this->withToken($token)
            ->getJson('/api/v1/admin/quotations?search='.$quotation->quotation_number)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.items.0.quotation_number', $quotation->quotation_number);
    }

    public function test_quotation_detail_exposes_the_full_review_context(): void
    {
        $quotation = $this->createQuotation(status: QuotationStatus::QuotationReady);
        $revision = $this->createRevision($quotation, 1);
        $quotation->statusHistories()->create([
            'status' => QuotationStatus::Submitted,
            'changed_by_type' => 'user',
            'changed_by_id' => 1,
        ]);

        [, $token] = $this->adminWithPermissions([AdminPermission::QuotationsView]);

        $this->withToken($token)
            ->getJson("/api/v1/admin/quotations/{$quotation->id}")
            ->assertOk()
            ->assertJsonPath('data.quotation_number', $quotation->quotation_number)
            ->assertJsonPath('data.latest_version', 1)
            ->assertJsonPath('data.can_accept', true)
            ->assertJsonPath('data.can_discuss', true)
            ->assertJsonPath('data.revisions.0.id', $revision->id)
            ->assertJsonPath('data.revisions.0.is_latest', true)
            ->assertJsonCount(1, 'data.timeline');
    }

    public function test_viewing_an_unknown_quotation_returns_not_found(): void
    {
        [, $token] = $this->adminWithPermissions([AdminPermission::QuotationsView]);

        $this->withToken($token)
            ->getJson('/api/v1/admin/quotations/999')
            ->assertNotFound()
            ->assertJsonPath('error_code', 'QUOTATION_NOT_FOUND');
    }

    public function test_first_reviewer_assignment_moves_the_quotation_under_review(): void
    {
        $quotation = $this->createQuotation(status: QuotationStatus::Submitted);
        $reviewer = Admin::factory()->create();

        [, $token] = $this->adminWithPermissions([AdminPermission::QuotationsReview]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/quotations/{$quotation->id}/assign", [
                'assigned_admin_id' => $reviewer->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'under_review')
            ->assertJsonPath('data.assigned_admin.id', $reviewer->id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'quotation_assign',
            'entity_type' => Quotation::class,
            'entity_id' => $quotation->id,
        ]);
    }

    public function test_reviewer_reassignment_keeps_the_status_and_is_audited(): void
    {
        $firstReviewer = Admin::factory()->create();
        $quotation = $this->createQuotation(status: QuotationStatus::UnderReview);
        $quotation->update(['assigned_admin_id' => $firstReviewer->id]);
        $secondReviewer = Admin::factory()->create();

        [, $token] = $this->adminWithPermissions([AdminPermission::QuotationsReview]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/quotations/{$quotation->id}/assign", [
                'assigned_admin_id' => $secondReviewer->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'under_review')
            ->assertJsonPath('data.assigned_admin.id', $secondReviewer->id);

        $auditLog = DB::table('audit_logs')
            ->where('action', 'quotation_assign')
            ->where('entity_id', $quotation->id)
            ->sole();
        $metadata = json_decode((string) $auditLog->metadata, true);

        self::assertSame($firstReviewer->id, $metadata['previous_reviewer_id']);
        self::assertSame($secondReviewer->id, $metadata['assigned_admin_id']);
        self::assertSame('under_review', $metadata['previous_status']);
        self::assertSame('under_review', $metadata['new_status']);
    }

    public function test_assignment_is_rejected_for_a_draft_quotation(): void
    {
        $quotation = $this->createQuotation(status: QuotationStatus::Draft);
        $reviewer = Admin::factory()->create();

        [, $token] = $this->adminWithPermissions([AdminPermission::QuotationsReview]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/quotations/{$quotation->id}/assign", [
                'assigned_admin_id' => $reviewer->id,
            ])
            ->assertConflict()
            ->assertJsonPath('error_code', 'QUOTATION_INVALID_STATE');
    }

    public function test_admin_can_issue_version_one_from_under_review(): void
    {
        $quotation = $this->createQuotation(status: QuotationStatus::UnderReview);

        [$admin, $token] = $this->adminWithPermissions([AdminPermission::QuotationsIssue]);

        $this->withToken($token)
            ->postJson("/api/v1/admin/quotations/{$quotation->id}/issue", $this->pricingPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'quotation_ready')
            ->assertJsonPath('data.latest_version', 1)
            ->assertJsonPath('data.latest_revision.version_number', 1)
            ->assertJsonPath('data.latest_revision.source', 'admin_issue');

        $this->assertDatabaseHas('quotation_revisions', [
            'quotation_id' => $quotation->id,
            'version_number' => 1,
            'issued_by_admin_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('quotations', [
            'id' => $quotation->id,
            'status' => 'quotation_ready',
            'subtotal' => 100,
            'total_amount' => 95,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'quotation_issue',
            'entity_type' => Quotation::class,
            'entity_id' => $quotation->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'title' => 'Quotation Issued',
        ]);
    }

    public function test_issue_is_rejected_outside_under_review(): void
    {
        $quotation = $this->createQuotation(status: QuotationStatus::Submitted);

        [, $token] = $this->adminWithPermissions([AdminPermission::QuotationsIssue]);

        $this->withToken($token)
            ->postJson("/api/v1/admin/quotations/{$quotation->id}/issue", $this->pricingPayload())
            ->assertConflict()
            ->assertJsonPath('error_code', 'QUOTATION_INVALID_STATE');
    }

    public function test_issue_rejects_inconsistent_totals(): void
    {
        $quotation = $this->createQuotation(status: QuotationStatus::UnderReview);

        [, $token] = $this->adminWithPermissions([AdminPermission::QuotationsIssue]);

        $this->withToken($token)
            ->postJson("/api/v1/admin/quotations/{$quotation->id}/issue", $this->pricingPayload([
                'total_amount' => '90.00',
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The total amount must equal subtotal minus discount plus tax.');
    }

    public function test_issue_requires_valid_until(): void
    {
        $quotation = $this->createQuotation(status: QuotationStatus::UnderReview);

        [, $token] = $this->adminWithPermissions([AdminPermission::QuotationsIssue]);

        $payload = $this->pricingPayload();
        unset($payload['valid_until']);

        $this->withToken($token)
            ->postJson("/api/v1/admin/quotations/{$quotation->id}/issue", $payload)
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['valid_until']]);
    }

    public function test_a_revision_creates_the_next_version_and_advances_latest_revision_id(): void
    {
        $quotation = $this->createQuotation(status: QuotationStatus::QuotationReady);
        $this->createRevision($quotation, 1);

        [, $token] = $this->adminWithPermissions([AdminPermission::QuotationsIssue]);

        $this->withToken($token)
            ->postJson("/api/v1/admin/quotations/{$quotation->id}/revisions", $this->pricingPayload([
                'subtotal' => '120.00',
                'total_amount' => '115.00',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.latest_version', 2)
            ->assertJsonPath('data.status', 'quotation_ready');

        $quotation->refresh();
        $latestRevision = QuotationRevision::query()
            ->where('quotation_id', $quotation->id)
            ->orderByDesc('version_number')
            ->first();

        self::assertSame(2, $latestRevision->version_number);
        self::assertSame($latestRevision->id, $quotation->latest_revision_id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'quotation_revision',
            'entity_type' => Quotation::class,
            'entity_id' => $quotation->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'title' => 'Quotation Updated',
        ]);
    }

    public function test_version_numbers_are_never_reused_after_many_revisions(): void
    {
        $quotation = $this->createQuotation(status: QuotationStatus::QuotationReady);
        $this->createRevision($quotation, 1);
        $this->createRevision($quotation, 2);
        $this->createRevision($quotation, 3);

        [, $token] = $this->adminWithPermissions([AdminPermission::QuotationsIssue]);

        $this->withToken($token)
            ->postJson("/api/v1/admin/quotations/{$quotation->id}/revisions", $this->pricingPayload())
            ->assertCreated()
            ->assertJsonPath('data.latest_version', 4);
    }

    public function test_a_revision_revives_an_expired_quotation(): void
    {
        $quotation = $this->createQuotation(status: QuotationStatus::Expired);
        $this->createRevision($quotation, 1, validUntil: now()->subDay());

        [, $token] = $this->adminWithPermissions([AdminPermission::QuotationsIssue]);

        $this->withToken($token)
            ->postJson("/api/v1/admin/quotations/{$quotation->id}/revisions", $this->pricingPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'quotation_ready')
            ->assertJsonPath('data.latest_version', 2);

        $this->assertDatabaseHas('quotation_status_histories', [
            'quotation_id' => $quotation->id,
            'status' => 'quotation_ready',
        ]);
    }

    public function test_a_revision_is_rejected_before_version_one_exists(): void
    {
        $quotation = $this->createQuotation(status: QuotationStatus::UnderReview);

        [, $token] = $this->adminWithPermissions([AdminPermission::QuotationsIssue]);

        $this->withToken($token)
            ->postJson("/api/v1/admin/quotations/{$quotation->id}/revisions", $this->pricingPayload())
            ->assertConflict()
            ->assertJsonPath('error_code', 'QUOTATION_INVALID_STATE');
    }

    public function test_admin_can_reply_on_the_discussion_thread(): void
    {
        $quotation = $this->createQuotation(status: QuotationStatus::UnderDiscussion);

        [, $token] = $this->adminWithPermissions([AdminPermission::QuotationsReview]);

        $this->withToken($token)
            ->postJson("/api/v1/admin/quotations/{$quotation->id}/discussion", [
                'message' => 'We have adjusted the scope.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.sender_type', 'admin')
            ->assertJsonPath('data.message', 'We have adjusted the scope.');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'quotation_discussion_reply',
            'entity_type' => Quotation::class,
            'entity_id' => $quotation->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'title' => 'New Discussion Message',
        ]);
    }

    public function test_admin_discussion_reply_is_rejected_outside_the_discussion_window(): void
    {
        $quotation = $this->createQuotation(status: QuotationStatus::Submitted);

        [, $token] = $this->adminWithPermissions([AdminPermission::QuotationsReview]);

        $this->withToken($token)
            ->postJson("/api/v1/admin/quotations/{$quotation->id}/discussion", [
                'message' => 'Too early.',
            ])
            ->assertConflict()
            ->assertJsonPath('error_code', 'QUOTATION_INVALID_STATE');
    }

    public function test_admin_can_close_a_discussion(): void
    {
        $quotation = $this->createQuotation(status: QuotationStatus::UnderDiscussion);
        $this->createRevision($quotation, 1);

        [, $token] = $this->adminWithPermissions([AdminPermission::QuotationsReview]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/quotations/{$quotation->id}/close-discussion")
            ->assertOk()
            ->assertJsonPath('data.status', 'quotation_ready');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'quotation_close_discussion',
            'entity_type' => Quotation::class,
            'entity_id' => $quotation->id,
        ]);
    }

    public function test_closing_a_discussion_requires_the_under_discussion_status(): void
    {
        $quotation = $this->createQuotation(status: QuotationStatus::QuotationReady);

        [, $token] = $this->adminWithPermissions([AdminPermission::QuotationsReview]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/quotations/{$quotation->id}/close-discussion")
            ->assertConflict()
            ->assertJsonPath('error_code', 'QUOTATION_INVALID_STATE');
    }

    public function test_admin_can_expire_an_issued_quotation(): void
    {
        $quotation = $this->createQuotation(status: QuotationStatus::QuotationReady);
        $this->createRevision($quotation, 1);

        [, $token] = $this->adminWithPermissions([AdminPermission::QuotationsManage]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/quotations/{$quotation->id}/expire")
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'quotation_expire',
            'entity_type' => Quotation::class,
            'entity_id' => $quotation->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'title' => 'Quotation Expired',
        ]);
    }

    public function test_expiring_requires_an_issued_quotation(): void
    {
        $quotation = $this->createQuotation(status: QuotationStatus::Submitted);

        [, $token] = $this->adminWithPermissions([AdminPermission::QuotationsManage]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/quotations/{$quotation->id}/expire")
            ->assertConflict()
            ->assertJsonPath('error_code', 'QUOTATION_INVALID_STATE');
    }

    public function test_admin_cancellation_requires_a_reason_and_writes_the_audit_payload(): void
    {
        $quotation = $this->createQuotation(status: QuotationStatus::UnderReview);

        [$admin, $token] = $this->adminWithPermissions([AdminPermission::QuotationsManage]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/quotations/{$quotation->id}/cancel")
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['reason']]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/quotations/{$quotation->id}/cancel", [
                'reason' => 'Duplicate request.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $auditLog = DB::table('audit_logs')
            ->where('action', 'quotation_cancel')
            ->where('entity_id', $quotation->id)
            ->sole();
        $metadata = json_decode((string) $auditLog->metadata, true);

        self::assertSame($admin->id, $auditLog->admin_id);
        self::assertSame($quotation->quotation_number, $metadata['quotation_number']);
        self::assertSame('under_review', $metadata['previous_status']);
        self::assertSame('cancelled', $metadata['new_status']);
        self::assertSame('Duplicate request.', $metadata['reason']);

        $this->assertDatabaseHas('notifications', [
            'title' => 'Quotation Cancelled',
        ]);
    }

    public function test_cancelling_a_terminal_quotation_returns_conflict(): void
    {
        $quotation = $this->createQuotation(status: QuotationStatus::Cancelled);

        [, $token] = $this->adminWithPermissions([AdminPermission::QuotationsManage]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/quotations/{$quotation->id}/cancel", [
                'reason' => 'Already closed.',
            ])
            ->assertConflict()
            ->assertJsonPath('error_code', 'QUOTATION_INVALID_STATE');
    }

    public function test_admin_can_accept_the_latest_revision_on_behalf_of_the_customer(): void
    {
        $booking = $this->createBooking();
        $quotation = $this->createQuotation(
            status: QuotationStatus::QuotationReady,
            profile: $booking->customerProfile,
            booking: $booking,
        );
        $latest = $this->createRevision($quotation, 1);

        [$admin, $token] = $this->adminWithPermissions([AdminPermission::QuotationsManage]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/quotations/{$quotation->id}/accept", [
                'revision_id' => $latest->id,
                'reason' => 'Customer confirmed by phone.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'accepted',
        ]);
        $this->assertDatabaseHas('quotation_status_histories', [
            'quotation_id' => $quotation->id,
            'status' => 'accepted',
            'changed_by_type' => 'admin',
            'changed_by_id' => $admin->id,
        ]);

        $auditLog = DB::table('audit_logs')
            ->where('action', 'quotation_admin_accept')
            ->where('entity_id', $quotation->id)
            ->sole();
        $metadata = json_decode((string) $auditLog->metadata, true);

        self::assertSame(1, $metadata['version_number']);
        self::assertSame('Customer confirmed by phone.', $metadata['reason']);
    }

    public function test_admin_acceptance_of_a_stale_revision_returns_conflict(): void
    {
        $quotation = $this->createQuotation(status: QuotationStatus::QuotationReady);
        $stale = $this->createRevision($quotation, 1);
        $this->createRevision($quotation, 2);

        [, $token] = $this->adminWithPermissions([AdminPermission::QuotationsManage]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/quotations/{$quotation->id}/accept", [
                'revision_id' => $stale->id,
                'reason' => 'Customer confirmed by phone.',
            ])
            ->assertConflict()
            ->assertJsonPath('error_code', 'QUOTATION_REVISION_STALE');
    }

    public function test_mutating_endpoints_require_their_specific_permissions(): void
    {
        $quotation = $this->createQuotation(status: QuotationStatus::Submitted);
        $reviewer = Admin::factory()->create();

        [, $token] = $this->adminWithPermissions([AdminPermission::QuotationsView]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/quotations/{$quotation->id}/assign", [
                'assigned_admin_id' => $reviewer->id,
            ])
            ->assertForbidden();

        $this->withToken($token)
            ->postJson("/api/v1/admin/quotations/{$quotation->id}/issue", $this->pricingPayload())
            ->assertForbidden();

        $this->withToken($token)
            ->patchJson("/api/v1/admin/quotations/{$quotation->id}/cancel", ['reason' => 'x'])
            ->assertForbidden();
    }

    /**
     * @param  list<AdminPermission>  $permissions
     * @return array{0: Admin, 1: string}
     */
    private function adminWithPermissions(array $permissions, AdminRole $role = AdminRole::Manager): array
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

        return [$admin, $admin->createToken('admin-panel')->plainTextToken];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function pricingPayload(array $overrides = []): array
    {
        return [
            'subtotal' => '100.00',
            'discount_amount' => '10.00',
            'tax_amount' => '5.00',
            'total_amount' => '95.00',
            'valid_until' => now()->addWeek()->toISOString(),
            'terms' => 'Payment due before service.',
            'notes' => 'Scope as discussed.',
            ...$overrides,
        ];
    }

    private function createQuotation(
        QuotationStatus $status,
        ?CustomerProfile $profile = null,
        ?Booking $booking = null,
    ): Quotation {
        $profile ??= CustomerProfile::factory()->create();

        return Quotation::query()->create([
            'quotation_number' => sprintf('QT-%s-%06d', now()->format('Y'), $this->sequence++),
            'customer_profile_id' => $profile->id,
            'booking_id' => $booking?->id,
            'status' => $status,
            'requirements' => 'Full villa deep cleaning.',
            'subtotal' => '0.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total_amount' => '0.00',
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

    private function createBooking(): Booking
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
        ]);
        $mode = ServiceModeOption::query()->create([
            'service_id' => $service->id,
            'mode' => ServiceMode::OneTime,
            'is_active' => true,
        ]);

        return Booking::query()->create([
            'booking_number' => sprintf('BK-%s-%06d', now()->format('Y'), $this->sequence++),
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
