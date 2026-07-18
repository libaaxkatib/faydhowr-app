<?php

namespace Tests\Feature\Api\V1\Reviews;

use App\Enums\ServiceMode;
use App\Models\CustomerProfile;
use App\Models\Review;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceModeOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicServiceReviewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_can_list_only_published_reviews_for_a_service(): void
    {
        $service = $this->createService();
        $published = Review::factory()->published()->create([
            'service_id' => $service->id,
            'rating' => 5,
            'comment' => 'Great service, arrived on time.',
        ]);
        Review::factory()->create(['service_id' => $service->id]);
        Review::factory()->hidden()->create(['service_id' => $service->id]);

        $response = $this->getJson("/api/v1/services/{$service->slug}/reviews");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.rating', 5)
            ->assertJsonPath('data.items.0.comment', 'Great service, arrived on time.')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissingPath('data.items.0.id')
            ->assertJsonMissingPath('data.items.0.status');
    }

    public function test_reviewer_name_is_first_name_plus_initial(): void
    {
        $service = $this->createService();
        $profile = CustomerProfile::factory()->create(['full_name' => 'Hodan Abdi']);
        Review::factory()->for($profile)->published()->create(['service_id' => $service->id]);

        $this
            ->getJson("/api/v1/services/{$service->slug}/reviews")
            ->assertOk()
            ->assertJsonPath('data.items.0.reviewer_name', 'Hodan A.');
    }

    public function test_soft_deleted_customers_display_verified_customer(): void
    {
        $service = $this->createService();
        $profile = CustomerProfile::factory()->create(['full_name' => 'Hodan Abdi']);
        Review::factory()->for($profile)->published()->create(['service_id' => $service->id]);

        $profile->delete();

        $this
            ->getJson("/api/v1/services/{$service->slug}/reviews")
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.reviewer_name', 'Verified Customer');
    }

    public function test_unknown_or_inactive_service_slug_returns_not_found(): void
    {
        $inactive = $this->createService(isActive: false);

        $this
            ->getJson('/api/v1/services/unknown-service/reviews')
            ->assertNotFound()
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this
            ->getJson("/api/v1/services/{$inactive->slug}/reviews")
            ->assertNotFound()
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_public_reviews_are_paginated_newest_first(): void
    {
        $service = $this->createService();
        $older = Review::factory()->published()->create(['service_id' => $service->id]);
        $older->forceFill(['created_at' => now()->subDay()])->save();
        $newer = Review::factory()->published()->create([
            'service_id' => $service->id,
            'title' => 'Newest review',
        ]);

        $this
            ->getJson("/api/v1/services/{$service->slug}/reviews?per_page=1")
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.title', $newer->title)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2);
    }

    private function createService(bool $isActive = true): Service
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
            'is_active' => $isActive,
            'sort_order' => 0,
        ]);

        ServiceModeOption::query()->create([
            'service_id' => $service->id,
            'mode' => ServiceMode::OneTime,
            'is_active' => true,
        ]);

        return $service;
    }
}
