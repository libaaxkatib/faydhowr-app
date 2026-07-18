<?php

namespace Tests\Feature\Api\V1\Search;

use App\Enums\ServiceMode;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceModeOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SearchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unified_search_ranks_exact_prefix_word_then_description_matches(): void
    {
        $this->createService(['name' => 'Deep Cleaning']);
        $this->createService(['name' => 'Clean']);
        $this->createService(['name' => 'Sofa Care', 'short_description' => 'Professional cleaning for sofas']);
        $this->createService(['name' => 'Cleaning Pro']);
        $this->createService(['name' => 'Pest Control', 'short_description' => 'No match here']);

        $response = $this->getJson('/api/v1/search?q=clean')->assertOk();

        $this->assertSame(
            ['Clean', 'Cleaning Pro', 'Deep Cleaning', 'Sofa Care'],
            array_column($response->json('data.services.items'), 'name'),
        );
    }

    public function test_search_ties_break_by_sort_order_then_featured_then_alphabetical(): void
    {
        $this->createService(['name' => 'Cleaning Beta', 'sort_order' => 2]);
        $this->createService(['name' => 'Cleaning Alpha', 'sort_order' => 2]);
        $this->createService(['name' => 'Cleaning Gamma', 'sort_order' => 2, 'is_featured' => true]);
        $this->createService(['name' => 'Cleaning Delta', 'sort_order' => 1]);

        $response = $this->getJson('/api/v1/search?q=cleaning&type=service')->assertOk();

        $this->assertSame(
            ['Cleaning Delta', 'Cleaning Gamma', 'Cleaning Alpha', 'Cleaning Beta'],
            array_column($response->json('data.services.items'), 'name'),
        );
    }

    public function test_unified_search_groups_services_and_products_with_meta_pagination(): void
    {
        $this->createService(['name' => 'Carpet Cleaning']);
        Product::factory()->create(['name' => 'Carpet Shampoo']);

        $response = $this->getJson('/api/v1/search?q=carpet')->assertOk();

        $this->assertSame('Carpet Cleaning', $response->json('data.services.items.0.name'));
        $this->assertSame(1, $response->json('data.services.meta.total'));
        $this->assertSame('Carpet Shampoo', $response->json('data.products.items.0.name'));
        $this->assertSame(1, $response->json('data.products.meta.total'));
    }

    public function test_unified_search_type_filter_limits_the_returned_groups(): void
    {
        $this->createService(['name' => 'Carpet Cleaning']);
        Product::factory()->create(['name' => 'Carpet Shampoo']);

        $serviceOnly = $this->getJson('/api/v1/search?q=carpet&type=service')->assertOk();
        $this->assertNull($serviceOnly->json('data.products'));
        $this->assertCount(1, $serviceOnly->json('data.services.items'));

        $productOnly = $this->getJson('/api/v1/search?q=carpet&type=product')->assertOk();
        $this->assertNull($productOnly->json('data.services'));
        $this->assertCount(1, $productOnly->json('data.products.items'));
    }

    public function test_unified_search_requires_a_query_of_at_least_two_characters(): void
    {
        $this->getJson('/api/v1/search?q=a')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');

        $this->getJson('/api/v1/search')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_product_search_matches_name_and_description_and_keeps_out_of_stock_visible(): void
    {
        Product::factory()->create(['name' => 'Vacuum Cleaner', 'current_stock' => 0]);
        Product::factory()->create(['name' => 'Mop Set', 'description' => 'Vacuum-compatible mop']);
        Product::factory()->inactive()->create(['name' => 'Vacuum Pro']);

        $response = $this->getJson('/api/v1/products/search?q=vacuum')->assertOk();

        $items = $response->json('data.items');

        $this->assertSame(['Vacuum Cleaner', 'Mop Set'], array_column($items, 'name'));
        $this->assertSame('out_of_stock', $items[0]['availability_status']);
        $this->assertArrayHasKey('selling_price', $items[0]);
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_product_search_supports_the_category_filter(): void
    {
        $product = Product::factory()->create(['name' => 'Vacuum Cleaner']);
        Product::factory()->create(['name' => 'Vacuum Bags']);

        $response = $this->getJson('/api/v1/products/search?q=vacuum&category_id='.$product->category_id)
            ->assertOk();

        $this->assertSame(['Vacuum Cleaner'], array_column($response->json('data.items'), 'name'));
    }

    public function test_product_search_returns_an_empty_page_for_no_matches(): void
    {
        $this->getJson('/api/v1/products/search?q=nothinghere')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('data.items', []);
    }

    public function test_suggestions_return_at_most_ten_results_with_only_the_documented_fields(): void
    {
        foreach (range(1, 7) as $index) {
            $this->createService(['name' => "Cleaning Service {$index}"]);
        }

        foreach (range(1, 7) as $index) {
            Product::factory()->create(['name' => "Cleaning Product {$index}"]);
        }

        $response = $this->getJson('/api/v1/search/suggestions?q=cleaning')->assertOk();

        $items = $response->json('data.items');

        $this->assertCount(10, $items);
        $this->assertSame(['type', 'name', 'slug', 'thumbnail'], array_keys($items[0]));

        $content = $response->getContent();
        $this->assertStringNotContainsString('selling_price', $content);
        $this->assertStringNotContainsString('current_stock', $content);
        $this->assertStringNotContainsString('discount', $content);
    }

    public function test_suggestions_rank_exact_matches_first_across_both_sources(): void
    {
        $this->createService(['name' => 'Deep Cleaning']);
        Product::factory()->create(['name' => 'Cleaning']);

        $response = $this->getJson('/api/v1/search/suggestions?q=cleaning')->assertOk();

        $this->assertSame('Cleaning', $response->json('data.items.0.name'));
        $this->assertSame('product', $response->json('data.items.0.type'));
    }

    public function test_suggestions_return_an_empty_list_for_short_or_missing_queries(): void
    {
        $this->getJson('/api/v1/search/suggestions?q=a')
            ->assertOk()
            ->assertJsonPath('data.items', []);

        $this->getJson('/api/v1/search/suggestions')
            ->assertOk()
            ->assertJsonPath('data.items', []);
    }

    public function test_suggestions_exclude_invisible_services_and_inactive_products(): void
    {
        $this->createService(['name' => 'Hidden Cleaning', 'is_active' => false]);
        Product::factory()->inactive()->create(['name' => 'Hidden Cleaning Product']);

        $this->getJson('/api/v1/search/suggestions?q=cleaning')
            ->assertOk()
            ->assertJsonPath('data.items', []);
    }

    public function test_service_search_endpoint_applies_the_documented_ranking(): void
    {
        $this->createService(['name' => 'Deep Cleaning']);
        $this->createService(['name' => 'Cleaning Pro']);

        $response = $this->getJson('/api/v1/services/search?q=cleaning')->assertOk();

        $this->assertSame(
            ['Cleaning Pro', 'Deep Cleaning'],
            array_column($response->json('data.items'), 'name'),
        );
    }

    public function test_all_search_endpoints_use_the_public_catalog_rate_limiter(): void
    {
        $uris = ['api/v1/search', 'api/v1/search/suggestions', 'api/v1/products/search'];

        $routes = collect(Route::getRoutes())
            ->filter(fn ($route): bool => in_array($route->uri(), $uris, true));

        $this->assertCount(3, $routes);

        foreach ($routes as $route) {
            $this->assertContains(
                'throttle:public-catalog',
                $route->gatherMiddleware(),
                "Route {$route->uri()} is missing the public-catalog throttle.",
            );
        }
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
