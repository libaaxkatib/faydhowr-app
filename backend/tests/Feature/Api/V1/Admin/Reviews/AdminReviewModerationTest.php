<?php

namespace Tests\Feature\Api\V1\Admin\Reviews;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Permission;
use App\Models\Review;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminReviewModerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_review_endpoints_require_permissions(): void
    {
        $review = Review::factory()->create();
        $tokenWithoutPermissions = $this->tokenWithPermissions([]);

        $this->withToken($tokenWithoutPermissions)
            ->getJson('/api/v1/admin/reviews')
            ->assertForbidden();

        $viewOnlyToken = $this->tokenWithPermissions([AdminPermission::ReviewsView]);

        $this->withToken($viewOnlyToken)
            ->patchJson("/api/v1/admin/reviews/{$review->id}/approve")
            ->assertForbidden();
    }

    public function test_admin_can_list_reviews_with_status_filter(): void
    {
        Review::factory()->create();
        Review::factory()->published()->create();

        $token = $this->tokenWithPermissions([AdminPermission::ReviewsView]);

        $this->withToken($token)
            ->getJson('/api/v1/admin/reviews')
            ->assertOk()
            ->assertJsonCount(2, 'data.items');

        $this->withToken($token)
            ->getJson('/api/v1/admin/reviews?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.status', 'pending');
    }

    public function test_admin_can_view_review_detail_with_context(): void
    {
        $review = Review::factory()->create(['rating' => 4]);
        $token = $this->tokenWithPermissions([AdminPermission::ReviewsView]);

        $this->withToken($token)
            ->getJson("/api/v1/admin/reviews/{$review->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $review->id)
            ->assertJsonPath('data.rating', 4)
            ->assertJsonPath('data.customer.id', $review->customer_profile_id)
            ->assertJsonPath('data.booking.id', $review->booking_id)
            ->assertJsonPath('data.service.id', $review->service_id);

        $this->withToken($token)
            ->getJson('/api/v1/admin/reviews/999999')
            ->assertNotFound()
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_approving_a_review_publishes_it_and_updates_service_aggregates(): void
    {
        $review = Review::factory()->create(['rating' => 5]);
        $token = $this->tokenWithPermissions([
            AdminPermission::ReviewsView,
            AdminPermission::ReviewsModerate,
        ]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/reviews/{$review->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $service = Service::query()->findOrFail($review->service_id);
        $this->assertSame(1, $service->reviews_count);
        $this->assertSame(5.0, (float) $service->average_rating);
    }

    public function test_hiding_a_review_recalculates_service_aggregates(): void
    {
        $first = Review::factory()->published()->create(['rating' => 5]);
        $second = Review::factory()->published()->create([
            'service_id' => $first->service_id,
            'rating' => 4,
        ]);

        $token = $this->tokenWithPermissions([
            AdminPermission::ReviewsView,
            AdminPermission::ReviewsModerate,
        ]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/reviews/{$second->id}/hide")
            ->assertOk()
            ->assertJsonPath('data.status', 'hidden');

        $service = Service::query()->findOrFail($first->service_id);
        $this->assertSame(1, $service->reviews_count);
        $this->assertSame(5.0, (float) $service->average_rating);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/reviews/{$first->id}/hide")
            ->assertOk();

        $service->refresh();
        $this->assertSame(0, $service->reviews_count);
        $this->assertNull($service->average_rating);
    }

    public function test_admin_can_re_moderate_between_published_and_hidden(): void
    {
        $review = Review::factory()->hidden()->create(['rating' => 3]);
        $token = $this->tokenWithPermissions([
            AdminPermission::ReviewsView,
            AdminPermission::ReviewsModerate,
        ]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/reviews/{$review->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $service = Service::query()->findOrFail($review->service_id);
        $this->assertSame(1, $service->reviews_count);
        $this->assertSame(3.0, (float) $service->average_rating);
    }

    public function test_average_rating_is_stored_with_two_decimal_precision(): void
    {
        $first = Review::factory()->published()->create(['rating' => 5]);
        Review::factory()->published()->create([
            'service_id' => $first->service_id,
            'rating' => 4,
        ]);
        $third = Review::factory()->create([
            'service_id' => $first->service_id,
            'rating' => 4,
        ]);

        $token = $this->tokenWithPermissions([
            AdminPermission::ReviewsView,
            AdminPermission::ReviewsModerate,
        ]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/reviews/{$third->id}/approve")
            ->assertOk();

        $service = Service::query()->findOrFail($first->service_id);
        $this->assertSame(3, $service->reviews_count);
        $this->assertSame(4.33, (float) $service->average_rating);
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
