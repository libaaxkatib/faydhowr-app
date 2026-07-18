<?php

namespace Tests\Feature\Api\V1\Favorites;

use App\Enums\ServiceMode;
use App\Models\CustomerProfile;
use App\Models\Favorite;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceModeOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FavoriteApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_favorites_endpoints_require_authentication(): void
    {
        $this->postJson('/api/v1/favorites', [])->assertUnauthorized();
        $this->getJson('/api/v1/favorites')->assertUnauthorized();
        $this->deleteJson('/api/v1/favorites/1')->assertUnauthorized();
    }

    public function test_inactive_customers_cannot_use_favorites(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->blocked()->for($user)->create();
        $token = $user->createToken('customer-mobile')->plainTextToken;
        $service = $this->createService();

        $this
            ->withToken($token)
            ->postJson('/api/v1/favorites', ['service_id' => $service->id])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ACCOUNT_INACTIVE');

        $this
            ->withToken($token)
            ->getJson('/api/v1/favorites')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ACCOUNT_INACTIVE');

        $this
            ->withToken($token)
            ->deleteJson("/api/v1/favorites/{$service->id}")
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ACCOUNT_INACTIVE');
    }

    public function test_first_add_returns_201_and_increments_favorites_count(): void
    {
        [$token, $profile] = $this->createCustomer();
        $service = $this->createService();

        $this
            ->withToken($token)
            ->postJson('/api/v1/favorites', ['service_id' => $service->id])
            ->assertCreated()
            ->assertJsonPath('data.slug', $service->slug)
            ->assertJsonPath('data.is_favorite', true);

        $this->assertDatabaseHas('favorites', [
            'customer_profile_id' => $profile->id,
            'service_id' => $service->id,
        ]);

        $this->assertSame(1, $service->refresh()->favorites_count);
    }

    public function test_adding_an_already_favorited_service_returns_200_without_duplicates(): void
    {
        [$token, $profile] = $this->createCustomer();
        $service = $this->createService();

        $this
            ->withToken($token)
            ->postJson('/api/v1/favorites', ['service_id' => $service->id])
            ->assertCreated();

        $this
            ->withToken($token)
            ->postJson('/api/v1/favorites', ['service_id' => $service->id])
            ->assertOk()
            ->assertJsonPath('data.is_favorite', true);

        $this->assertSame(1, Favorite::query()
            ->where('customer_profile_id', $profile->id)
            ->where('service_id', $service->id)
            ->count());

        $this->assertSame(1, $service->refresh()->favorites_count);
    }

    public function test_adding_unknown_or_inactive_service_returns_404(): void
    {
        [$token] = $this->createCustomer();
        $inactive = $this->createService(active: false);

        $this
            ->withToken($token)
            ->postJson('/api/v1/favorites', ['service_id' => 999999])
            ->assertNotFound()
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this
            ->withToken($token)
            ->postJson('/api/v1/favorites', ['service_id' => $inactive->id])
            ->assertNotFound()
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this->assertSame(0, Favorite::query()->count());
    }

    public function test_add_requires_a_valid_service_id(): void
    {
        [$token] = $this->createCustomer();

        $this
            ->withToken($token)
            ->postJson('/api/v1/favorites', [])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['service_id']);
    }

    public function test_removing_an_existing_favorite_returns_200_and_decrements_count(): void
    {
        [$token, $profile] = $this->createCustomer();
        $service = $this->createService();
        Favorite::factory()->create([
            'customer_profile_id' => $profile->id,
            'service_id' => $service->id,
        ]);
        Service::query()->whereKey($service->id)->update(['favorites_count' => 1]);

        $this
            ->withToken($token)
            ->deleteJson("/api/v1/favorites/{$service->id}")
            ->assertOk();

        $this->assertDatabaseMissing('favorites', [
            'customer_profile_id' => $profile->id,
            'service_id' => $service->id,
        ]);

        $this->assertSame(0, $service->refresh()->favorites_count);
    }

    public function test_removing_a_not_favorited_service_is_idempotent(): void
    {
        [$token] = $this->createCustomer();
        $service = $this->createService();

        $this
            ->withToken($token)
            ->deleteJson("/api/v1/favorites/{$service->id}")
            ->assertOk();

        $this->assertSame(0, $service->refresh()->favorites_count);
    }

    public function test_removing_unknown_or_inactive_service_returns_404(): void
    {
        [$token] = $this->createCustomer();
        $inactive = $this->createService(active: false);

        $this
            ->withToken($token)
            ->deleteJson('/api/v1/favorites/999999')
            ->assertNotFound()
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this
            ->withToken($token)
            ->deleteJson("/api/v1/favorites/{$inactive->id}")
            ->assertNotFound()
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_removal_does_not_affect_other_customers_favorites(): void
    {
        [$token] = $this->createCustomer();
        $service = $this->createService();
        $foreign = Favorite::factory()->create(['service_id' => $service->id]);
        Service::query()->whereKey($service->id)->update(['favorites_count' => 1]);

        $this
            ->withToken($token)
            ->deleteJson("/api/v1/favorites/{$service->id}")
            ->assertOk();

        $this->assertDatabaseHas('favorites', ['id' => $foreign->id]);
        $this->assertSame(1, $service->refresh()->favorites_count);
    }

    public function test_favorites_list_returns_own_service_cards_newest_first(): void
    {
        [$token, $profile] = $this->createCustomer();
        $older = $this->createService();
        $newer = $this->createService();

        Favorite::factory()->create([
            'customer_profile_id' => $profile->id,
            'service_id' => $older->id,
            'created_at' => now()->subDay(),
        ]);
        Favorite::factory()->create([
            'customer_profile_id' => $profile->id,
            'service_id' => $newer->id,
        ]);
        Favorite::factory()->create();

        $response = $this
            ->withToken($token)
            ->getJson('/api/v1/favorites')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.slug', $newer->slug)
            ->assertJsonPath('data.items.1.slug', $older->slug)
            ->assertJsonPath('data.items.0.is_favorite', true)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 20);

        $this->assertArrayHasKey('images', $response->json('data.items.0'));
        $this->assertArrayHasKey('modes', $response->json('data.items.0'));
    }

    public function test_favorites_list_excludes_services_that_became_unavailable(): void
    {
        [$token, $profile] = $this->createCustomer();
        $service = $this->createService();
        $favorite = Favorite::factory()->create([
            'customer_profile_id' => $profile->id,
            'service_id' => $service->id,
        ]);

        // Bypass the observer to simulate a stale favorite row.
        Service::withoutEvents(fn () => $service->update(['is_active' => false]));

        $this
            ->withToken($token)
            ->getJson('/api/v1/favorites')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');

        $this->assertDatabaseHas('favorites', ['id' => $favorite->id]);
    }

    public function test_favorites_list_pagination_respects_per_page_bounds(): void
    {
        [$token, $profile] = $this->createCustomer();

        foreach (range(1, 3) as $ignored) {
            Favorite::factory()->create(['customer_profile_id' => $profile->id]);
        }

        $this
            ->withToken($token)
            ->getJson('/api/v1/favorites?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);

        $this
            ->withToken($token)
            ->getJson('/api/v1/favorites?per_page=101')
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_favorites_endpoints_are_rate_limited_per_customer(): void
    {
        [$token] = $this->createCustomer();

        for ($i = 0; $i < 30; $i++) {
            $this->withToken($token)->getJson('/api/v1/favorites');
        }

        $this
            ->withToken($token)
            ->getJson('/api/v1/favorites')
            ->assertTooManyRequests();
    }

    /**
     * @return array{string, CustomerProfile}
     */
    private function createCustomer(): array
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->for($user)->create();

        return [$user->createToken('customer-mobile')->plainTextToken, $profile];
    }

    private function createService(bool $active = true): Service
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
            'is_active' => $active,
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
