<?php

namespace Tests\Feature\Api\V1\Admin\Content;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Enums\ServiceMode;
use App\Models\Admin;
use App\Models\BeforeAfterItem;
use App\Models\Faq;
use App\Models\HeroBanner;
use App\Models\Permission;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceModeOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminHomeContentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/hero-banners')->assertUnauthorized();
        $this->postJson('/api/v1/admin/faqs', [])->assertUnauthorized();
    }

    public function test_listing_content_requires_the_content_view_permission(): void
    {
        [, $token] = $this->adminWithPermissions([]);

        $this->withToken($token)->getJson('/api/v1/admin/hero-banners')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/admin/before-after')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/admin/faqs')->assertForbidden();
    }

    public function test_mutations_require_the_content_manage_permission(): void
    {
        [, $token] = $this->adminWithPermissions([AdminPermission::ContentView]);

        $this->withToken($token)->postJson('/api/v1/admin/hero-banners', [])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/admin/faqs', [])->assertForbidden();

        $service = $this->createService();
        $this->withToken($token)
            ->patchJson("/api/v1/admin/services/{$service->id}/featured", ['is_featured' => true])
            ->assertForbidden();
    }

    public function test_admin_listing_includes_inactive_and_out_of_schedule_banners(): void
    {
        HeroBanner::factory()->inactive()->create();
        HeroBanner::factory()->scheduled(now()->addWeek()->toDateTimeString(), null)->create();

        [, $token] = $this->adminWithPermissions([AdminPermission::ContentView]);

        $this->withToken($token)
            ->getJson('/api/v1/admin/hero-banners')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_admin_can_create_a_hero_banner_and_the_mutation_is_audited(): void
    {
        [$admin, $token] = $this->adminWithPermissions([AdminPermission::ContentManage]);

        $response = $this->withToken($token)
            ->postJson('/api/v1/admin/hero-banners', [
                'title' => 'Eid Promotion',
                'subtitle' => 'Book early',
                'image_url' => 'https://cdn.example.com/banners/eid.jpg',
                'action_type' => 'url',
                'action_reference' => 'https://fayadhowr.example/promo',
                'sort_order' => 1,
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Eid Promotion')
            ->assertJsonPath('data.action_type', 'url');

        $this->assertDatabaseHas('hero_banners', ['title' => 'Eid Promotion']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'hero_banner_create',
            'entity_type' => HeroBanner::class,
            'entity_id' => $response->json('data.id'),
            'admin_id' => $admin->id,
        ]);
    }

    public function test_banner_creation_requires_an_action_reference_for_actionable_banners(): void
    {
        [, $token] = $this->adminWithPermissions([AdminPermission::ContentManage]);

        $this->withToken($token)
            ->postJson('/api/v1/admin/hero-banners', [
                'title' => 'Broken Banner',
                'image_url' => 'https://cdn.example.com/banners/x.jpg',
                'action_type' => 'service',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');

        $this->withToken($token)
            ->postJson('/api/v1/admin/hero-banners', [
                'title' => 'Broken Banner',
                'image_url' => 'https://cdn.example.com/banners/x.jpg',
                'action_type' => 'none',
                'action_reference' => 'https://example.com',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_banner_schedule_must_end_after_it_starts(): void
    {
        [, $token] = $this->adminWithPermissions([AdminPermission::ContentManage]);

        $this->withToken($token)
            ->postJson('/api/v1/admin/hero-banners', [
                'title' => 'Backwards Schedule',
                'image_url' => 'https://cdn.example.com/banners/x.jpg',
                'action_type' => 'none',
                'starts_at' => now()->addWeek()->toDateTimeString(),
                'ends_at' => now()->toDateTimeString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_banner_updates_enforce_invariants_against_the_merged_state(): void
    {
        $banner = HeroBanner::factory()->withUrlAction()->create();

        [, $token] = $this->adminWithPermissions([AdminPermission::ContentManage]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/hero-banners/{$banner->id}", ['action_reference' => null])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_hiding_and_publishing_a_banner_emit_dedicated_audit_events(): void
    {
        $banner = HeroBanner::factory()->create();

        [$admin, $token] = $this->adminWithPermissions([AdminPermission::ContentManage]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/hero-banners/{$banner->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'hero_banner_hide',
            'entity_id' => $banner->id,
            'admin_id' => $admin->id,
        ]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/hero-banners/{$banner->id}", ['is_active' => true])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'hero_banner_publish',
            'entity_id' => $banner->id,
        ]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/hero-banners/{$banner->id}", ['title' => 'Renamed'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'hero_banner_update',
            'entity_id' => $banner->id,
        ]);
    }

    public function test_deleting_a_banner_soft_deletes_and_audits_it(): void
    {
        $banner = HeroBanner::factory()->create();

        [, $token] = $this->adminWithPermissions([AdminPermission::ContentManage]);

        $this->withToken($token)
            ->deleteJson("/api/v1/admin/hero-banners/{$banner->id}")
            ->assertOk();

        $this->assertSoftDeleted('hero_banners', ['id' => $banner->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'hero_banner_delete',
            'entity_id' => $banner->id,
        ]);
    }

    public function test_unknown_content_ids_return_not_found(): void
    {
        [, $token] = $this->adminWithPermissions([AdminPermission::ContentManage]);

        $this->withToken($token)
            ->patchJson('/api/v1/admin/hero-banners/999', ['title' => 'X'])
            ->assertNotFound()
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this->withToken($token)
            ->deleteJson('/api/v1/admin/faqs/999')
            ->assertNotFound();

        $this->withToken($token)
            ->patchJson('/api/v1/admin/services/999/featured', ['is_featured' => true])
            ->assertNotFound();
    }

    public function test_admin_can_manage_before_after_items_with_audit_trail(): void
    {
        $service = $this->createService();

        [$admin, $token] = $this->adminWithPermissions([AdminPermission::ContentManage]);

        $created = $this->withToken($token)
            ->postJson('/api/v1/admin/before-after', [
                'title' => 'Villa Deep Clean',
                'before_image_url' => 'https://cdn.example.com/gallery/before.jpg',
                'after_image_url' => 'https://cdn.example.com/gallery/after.jpg',
                'service_id' => $service->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.service.slug', $service->slug);

        $itemId = $created->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'gallery_create',
            'entity_type' => BeforeAfterItem::class,
            'entity_id' => $itemId,
            'admin_id' => $admin->id,
        ]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/before-after/{$itemId}", ['title' => 'Villa Deep Clean 2'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Villa Deep Clean 2');

        $this->assertDatabaseHas('audit_logs', ['action' => 'gallery_update', 'entity_id' => $itemId]);

        $this->withToken($token)
            ->deleteJson("/api/v1/admin/before-after/{$itemId}")
            ->assertOk();

        $this->assertSoftDeleted('before_after_items', ['id' => $itemId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'gallery_delete', 'entity_id' => $itemId]);
    }

    public function test_before_after_items_require_an_existing_service_when_linked(): void
    {
        [, $token] = $this->adminWithPermissions([AdminPermission::ContentManage]);

        $this->withToken($token)
            ->postJson('/api/v1/admin/before-after', [
                'title' => 'Orphan Item',
                'before_image_url' => 'https://cdn.example.com/gallery/before.jpg',
                'after_image_url' => 'https://cdn.example.com/gallery/after.jpg',
                'service_id' => 999,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_admin_can_manage_faqs_with_audit_trail(): void
    {
        [$admin, $token] = $this->adminWithPermissions([AdminPermission::ContentManage]);

        $created = $this->withToken($token)
            ->postJson('/api/v1/admin/faqs', [
                'question' => 'Do you clean carpets?',
                'answer' => 'Yes, we do.',
            ])
            ->assertCreated();

        $faqId = $created->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'faq_create',
            'entity_type' => Faq::class,
            'entity_id' => $faqId,
            'admin_id' => $admin->id,
        ]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/faqs/{$faqId}", ['answer' => 'Absolutely.'])
            ->assertOk()
            ->assertJsonPath('data.answer', 'Absolutely.');

        $this->assertDatabaseHas('audit_logs', ['action' => 'faq_update', 'entity_id' => $faqId]);

        $this->withToken($token)
            ->deleteJson("/api/v1/admin/faqs/{$faqId}")
            ->assertOk();

        $this->assertSoftDeleted('faqs', ['id' => $faqId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'faq_delete', 'entity_id' => $faqId]);
    }

    public function test_admin_can_toggle_the_featured_state_of_a_service(): void
    {
        $service = $this->createService();

        [$admin, $token] = $this->adminWithPermissions([AdminPermission::ContentManage]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/services/{$service->id}/featured", [
                'is_featured' => true,
                'sort_order' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_featured', true)
            ->assertJsonPath('data.sort_order', 3);

        $this->assertDatabaseHas('services', ['id' => $service->id, 'is_featured' => true, 'sort_order' => 3]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'service_feature_toggle',
            'entity_type' => Service::class,
            'entity_id' => $service->id,
            'admin_id' => $admin->id,
        ]);
    }

    public function test_admin_content_mutations_invalidate_the_public_home_cache(): void
    {
        Faq::factory()->create(['question' => 'Original question?']);

        $this->getJson('/api/v1/home/faq')->assertOk()->assertJsonPath('meta.total', 1);

        [, $token] = $this->adminWithPermissions([AdminPermission::ContentManage]);

        $this->withToken($token)
            ->postJson('/api/v1/admin/faqs', [
                'question' => 'Brand new question?',
                'answer' => 'Brand new answer.',
            ])
            ->assertCreated();

        $this->getJson('/api/v1/home/faq')->assertOk()->assertJsonPath('meta.total', 2);
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
     */
    private function createService(array $overrides = []): Service
    {
        $category = ServiceCategory::query()->create([
            'name' => fake()->unique()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $service = Service::query()->create(array_merge([
            'category_id' => $category->id,
            'name' => fake()->unique()->words(2, true),
            'slug' => fake()->unique()->slug(3),
            'currency' => 'USD',
            'requires_address' => true,
            'is_active' => true,
            'sort_order' => 0,
        ], $overrides));

        ServiceModeOption::query()->create([
            'service_id' => $service->id,
            'mode' => ServiceMode::OneTime,
            'is_active' => true,
        ]);

        return $service;
    }
}
