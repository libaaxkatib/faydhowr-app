<?php

namespace Tests\Feature\Api\V1\Catalog;

use App\Enums\ServiceMode;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function createCategory(array $attributes = []): ServiceCategory
    {
        static $sequence = 0;
        $sequence++;

        return ServiceCategory::query()->create(array_merge([
            'name' => "Category {$sequence}",
            'slug' => "category-{$sequence}",
            'sort_order' => $sequence,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * @param  list<array{0: ServiceMode, 1: ?string}>  $modes
     */
    private function createService(
        ServiceCategory $category,
        array $attributes = [],
        array $modes = [[ServiceMode::OneTime, null]],
        array $cities = ['Mogadishu', 'Hargeisa'],
        bool $withMedia = true,
    ): Service {
        static $sequence = 0;
        $sequence++;

        $service = Service::query()->create(array_merge([
            'category_id' => $category->id,
            'name' => "Service {$sequence}",
            'slug' => "service-{$sequence}",
            'short_description' => "Short description {$sequence}",
            'description' => "Long description {$sequence}",
            'currency' => 'USD',
            'is_active' => true,
            'sort_order' => $sequence,
        ], $attributes));

        foreach ($modes as [$mode, $subtype]) {
            $service->modes()->create([
                'mode' => $mode->value,
                'subtype' => $subtype,
                'is_active' => true,
            ]);
        }

        foreach ($cities as $city) {
            $service->coverageCities()->create(['city' => $city, 'is_active' => true]);
        }

        if ($withMedia) {
            $service->media()->create([
                'media_type' => 'image',
                'url' => "https://cdn.example.com/{$service->slug}/hero.jpg",
                'sort_order' => 0,
                'is_primary' => true,
            ]);
            $service->media()->create([
                'media_type' => 'image',
                'url' => "https://cdn.example.com/{$service->slug}/gallery-1.jpg",
                'sort_order' => 1,
                'is_primary' => false,
            ]);
        }

        return $service;
    }

    public function test_list_returns_visible_services_with_card_shape(): void
    {
        $category = $this->createCategory();
        $service = $this->createService($category, [
            'starting_from_price' => '25.00',
        ]);

        $response = $this->getJson('/api/v1/services');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('data.items.0.slug', $service->slug)
            ->assertJsonPath('data.items.0.currency', 'USD')
            ->assertJsonPath('data.items.0.images.thumbnail', "https://cdn.example.com/{$service->slug}/hero.jpg")
            ->assertJsonPath('data.items.0.images.hero_image', "https://cdn.example.com/{$service->slug}/hero.jpg")
            ->assertJsonPath('data.items.0.images.gallery.0', "https://cdn.example.com/{$service->slug}/gallery-1.jpg")
            ->assertJsonStructure([
                'data' => [
                    'items' => [
                        '*' => [
                            'slug',
                            'name',
                            'short_description',
                            'starting_from_price',
                            'currency',
                            'modes' => ['*' => ['mode', 'subtype']],
                            'coverage_cities',
                            'images' => ['thumbnail', 'hero_image', 'gallery'],
                        ],
                    ],
                ],
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            ]);

        $card = $response->json('data.items.0');
        $this->assertArrayNotHasKey('id', $card);
        $this->assertArrayNotHasKey('is_favorite', $card);
        $this->assertArrayNotHasKey('before_after', $card);
        $this->assertArrayNotHasKey('faq', $card);
    }

    public function test_list_hides_inactive_deleted_and_modeless_services(): void
    {
        $category = $this->createCategory();

        $visible = $this->createService($category);
        $this->createService($category, ['is_active' => false]);
        $this->createService($category)->delete();

        $modeless = $this->createService($category, [], modes: []);
        $withInactiveMode = $this->createService($category, [], modes: []);
        $withInactiveMode->modes()->create([
            'mode' => ServiceMode::OneTime->value,
            'subtype' => null,
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/v1/services');

        $response->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame($visible->slug, $response->json('data.items.0.slug'));
        $this->assertNotContains($modeless->slug, array_column($response->json('data.items'), 'slug'));
    }

    public function test_list_filters_by_category_mode_and_city(): void
    {
        $cleaning = $this->createCategory();
        $staffing = $this->createCategory();

        $oneTimeMogadishu = $this->createService(
            $cleaning,
            modes: [[ServiceMode::OneTime, null]],
            cities: ['Mogadishu'],
        );
        $contractHargeisa = $this->createService(
            $staffing,
            modes: [[ServiceMode::MonthlyContract, 'full_time']],
            cities: ['Hargeisa'],
        );

        $byCategory = $this->getJson("/api/v1/services?category_id={$cleaning->id}");
        $byCategory->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame($oneTimeMogadishu->slug, $byCategory->json('data.items.0.slug'));

        $byMode = $this->getJson('/api/v1/services?mode=monthly_contract');
        $byMode->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame($contractHargeisa->slug, $byMode->json('data.items.0.slug'));

        $byCity = $this->getJson('/api/v1/services?city=Mogadishu');
        $byCity->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame($oneTimeMogadishu->slug, $byCity->json('data.items.0.slug'));
    }

    public function test_list_sorts_by_display_order_by_default_and_by_name_optionally(): void
    {
        $category = $this->createCategory();

        $second = $this->createService($category, ['name' => 'Alpha Service', 'sort_order' => 2]);
        $first = $this->createService($category, ['name' => 'Zulu Service', 'sort_order' => 1]);

        $default = $this->getJson('/api/v1/services');
        $this->assertSame(
            [$first->slug, $second->slug],
            array_column($default->json('data.items'), 'slug'),
        );

        $byName = $this->getJson('/api/v1/services?sort=name');
        $this->assertSame(
            [$second->slug, $first->slug],
            array_column($byName->json('data.items'), 'slug'),
        );
    }

    public function test_list_rejects_invalid_filters_sort_and_pagination(): void
    {
        $this->getJson('/api/v1/services?sort=price')
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');

        $this->getJson('/api/v1/services?mode=weekly')
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');

        $this->getJson('/api/v1/services?city=Berbera')
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');

        $this->getJson('/api/v1/services?per_page=150')
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_list_paginates_with_default_twenty_per_page(): void
    {
        $category = $this->createCategory();

        for ($i = 0; $i < 21; $i++) {
            $this->createService($category, withMedia: false);
        }

        $response = $this->getJson('/api/v1/services');

        $response
            ->assertOk()
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 21)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(20, 'data.items');
    }

    public function test_relative_media_urls_are_returned_absolute(): void
    {
        $category = $this->createCategory();
        $service = $this->createService($category, withMedia: false);

        $service->media()->create([
            'media_type' => 'image',
            'url' => '/storage/services/local-hero.jpg',
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        $response = $this->getJson("/api/v1/services/{$service->slug}");

        $thumbnail = $response->json('data.images.thumbnail');
        $this->assertStringStartsWith('http', $thumbnail);
        $this->assertStringEndsWith('/storage/services/local-hero.jpg', $thumbnail);
    }

    public function test_detail_returns_full_shape_by_slug(): void
    {
        $category = $this->createCategory();
        $service = $this->createService($category, [
            'inclusions' => 'All rooms',
            'exclusions' => 'Exterior walls',
        ], modes: [[ServiceMode::MonthlyContract, 'office']]);

        $response = $this->getJson("/api/v1/services/{$service->slug}");

        $response
            ->assertOk()
            ->assertJsonPath('data.slug', $service->slug)
            ->assertJsonPath('data.inclusions', 'All rooms')
            ->assertJsonPath('data.exclusions', 'Exterior walls')
            ->assertJsonPath('data.modes.0.mode', 'monthly_contract')
            ->assertJsonPath('data.modes.0.subtype', 'office')
            ->assertJsonPath('data.actions.book_now', true)
            ->assertJsonPath('data.actions.request_quotation', true)
            ->assertJsonStructure([
                'data' => [
                    'slug', 'name', 'short_description', 'description',
                    'inclusions', 'exclusions', 'starting_from_price', 'currency',
                    'modes', 'coverage_cities',
                    'images' => ['thumbnail', 'hero_image', 'gallery'],
                    'actions' => ['book_now', 'request_quotation'],
                ],
            ]);

        $detail = $response->json('data');
        $this->assertArrayNotHasKey('id', $detail);
        $this->assertArrayNotHasKey('is_favorite', $detail);
    }

    public function test_detail_returns_404_for_unknown_inactive_or_deleted_services(): void
    {
        $category = $this->createCategory();

        $this->getJson('/api/v1/services/does-not-exist')
            ->assertNotFound()
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $inactive = $this->createService($category, ['is_active' => false]);
        $this->getJson("/api/v1/services/{$inactive->slug}")
            ->assertNotFound()
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $deleted = $this->createService($category);
        $deleted->delete();
        $this->getJson("/api/v1/services/{$deleted->slug}")
            ->assertNotFound()
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_categories_returns_only_active_categories_with_visible_services(): void
    {
        $withService = $this->createCategory(['sort_order' => 2]);
        $this->createService($withService);

        $alsoWithService = $this->createCategory(['sort_order' => 1]);
        $this->createService($alsoWithService);

        $empty = $this->createCategory();

        $onlyInactiveService = $this->createCategory();
        $this->createService($onlyInactiveService, ['is_active' => false]);

        $inactiveCategory = $this->createCategory(['is_active' => false]);
        $this->createService($inactiveCategory);

        $response = $this->getJson('/api/v1/service-categories');

        $response->assertOk()->assertJsonCount(2, 'data');

        $slugs = array_column($response->json('data'), 'slug');
        $this->assertSame([$alsoWithService->slug, $withService->slug], $slugs);

        $this->assertSame(['slug', 'name'], array_keys($response->json('data.0')));
    }

    public function test_search_matches_name_and_short_description_only(): void
    {
        $category = $this->createCategory();

        $byName = $this->createService($category, ['name' => 'Deep Cleaning', 'slug' => 'deep-cleaning']);
        $byShortDescription = $this->createService($category, [
            'name' => 'Fumigation',
            'slug' => 'fumigation',
            'short_description' => 'Includes deep sanitization',
        ]);
        $this->createService($category, [
            'name' => 'Window Washing',
            'slug' => 'window-washing',
            'short_description' => 'Streak-free glass',
            'description' => 'A deep and thorough process',
        ]);

        $response = $this->getJson('/api/v1/services/search?q=deep');

        $response->assertOk()->assertJsonPath('meta.total', 2);

        $slugs = array_column($response->json('data.items'), 'slug');
        $this->assertContains($byName->slug, $slugs);
        $this->assertContains($byShortDescription->slug, $slugs);
    }

    public function test_search_requires_minimum_two_characters(): void
    {
        $this->getJson('/api/v1/services/search')
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['q']);

        $this->getJson('/api/v1/services/search?q=a')
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['q']);
    }

    public function test_search_with_no_results_returns_empty_list(): void
    {
        $this->getJson('/api/v1/services/search?q=nonexistent')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data.items');
    }

    public function test_catalog_endpoints_are_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/service-categories')->assertOk();
        }

        $this->getJson('/api/v1/service-categories')->assertTooManyRequests();
    }
}
