<?php

namespace Tests\Feature\Api\V1\Home;

use App\Enums\Review\ReviewStatus;
use App\Enums\ServiceMode;
use App\Models\BeforeAfterItem;
use App\Models\Faq;
use App\Models\HeroBanner;
use App\Models\Product;
use App\Models\Review;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceModeOption;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class HomeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_aggregate_returns_sections_in_the_approved_order_with_metadata(): void
    {
        $response = $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.version', 'v1');

        $this->assertSame(
            [
                'hero_banners',
                'service_categories',
                'featured_services',
                'popular_services',
                'featured_products',
                'before_after',
                'reviews',
                'faq',
                'contact',
            ],
            array_keys($response->json('data.sections')),
        );

        $this->assertNotNull($response->json('meta.generated_at'));
        $this->assertNotNull($response->json('meta.cache_expires_at'));
    }

    public function test_only_active_banners_inside_their_schedule_appear_publicly(): void
    {
        $visible = HeroBanner::factory()->create(['title' => 'Visible Banner']);
        HeroBanner::factory()->scheduled(now()->subDay()->toDateTimeString(), now()->addDay()->toDateTimeString())
            ->create(['title' => 'Scheduled Banner']);
        HeroBanner::factory()->inactive()->create(['title' => 'Inactive Banner']);
        HeroBanner::factory()->scheduled(now()->addDay()->toDateTimeString(), null)
            ->create(['title' => 'Future Banner']);
        HeroBanner::factory()->scheduled(null, now()->subDay()->toDateTimeString())
            ->create(['title' => 'Expired Banner']);

        $response = $this->getJson('/api/v1/home/hero-banners')->assertOk();

        $titles = array_column($response->json('data.items'), 'title');

        $this->assertSame(['Visible Banner', 'Scheduled Banner'], $titles);
        $this->assertSame($visible->id, $response->json('data.items.0.id'));
    }

    public function test_hero_banner_payload_matches_the_documented_contract(): void
    {
        HeroBanner::factory()->withUrlAction()->create();

        $response = $this->getJson('/api/v1/home/hero-banners')->assertOk();

        $this->assertSame(
            ['id', 'title', 'subtitle', 'image_url', 'action_type', 'action_reference', 'sort_order'],
            array_keys($response->json('data.items.0')),
        );
        $this->assertSame('url', $response->json('data.items.0.action_type'));
    }

    public function test_featured_services_lists_only_active_featured_services_ordered_by_sort_order(): void
    {
        $this->createService(['name' => 'Second Featured', 'is_featured' => true, 'sort_order' => 5]);
        $this->createService(['name' => 'First Featured', 'is_featured' => true, 'sort_order' => 1]);
        $this->createService(['name' => 'Not Featured', 'is_featured' => false]);
        $this->createService(['name' => 'Inactive Featured', 'is_featured' => true, 'is_active' => false]);

        $response = $this->getJson('/api/v1/home/featured-services')->assertOk();

        $names = array_column($response->json('data.items'), 'name');

        $this->assertSame(['First Featured', 'Second Featured'], $names);
    }

    public function test_popular_services_are_ranked_by_favorites_count_which_is_never_exposed(): void
    {
        $this->createService(['name' => 'Barely Popular', 'favorites_count' => 1]);
        $this->createService(['name' => 'Most Popular', 'favorites_count' => 9]);
        $this->createService(['name' => 'Quite Popular', 'favorites_count' => 5]);

        $response = $this->getJson('/api/v1/home')->assertOk();

        $popular = $response->json('data.sections.popular_services');

        $this->assertSame(
            ['Most Popular', 'Quite Popular', 'Barely Popular'],
            array_column($popular, 'name'),
        );

        $this->assertStringNotContainsString('favorites_count', $response->getContent());
    }

    public function test_store_products_keep_out_of_stock_items_visible_with_an_availability_state(): void
    {
        Product::factory()->featured()->create(['name' => 'Featured Product', 'current_stock' => 20, 'low_stock_threshold' => 5]);
        Product::factory()->create(['name' => 'Sold Out Product', 'current_stock' => 0]);
        Product::factory()->inactive()->create(['name' => 'Inactive Product']);

        $response = $this->getJson('/api/v1/home/store-products')->assertOk();

        $items = collect($response->json('data.items'));

        $this->assertSame('Featured Product', $items[0]['name']);
        $this->assertSame('in_stock', $items[0]['availability_status']);

        $soldOut = $items->firstWhere('name', 'Sold Out Product');
        $this->assertSame('out_of_stock', $soldOut['availability_status']);
        $this->assertArrayHasKey('selling_price', $soldOut);
        $this->assertArrayHasKey('currency', $soldOut);

        $this->assertNull($items->firstWhere('name', 'Inactive Product'));
    }

    public function test_before_after_section_paginates_active_items_with_meta(): void
    {
        $service = $this->createService(['name' => 'Deep Cleaning']);
        BeforeAfterItem::factory()->create(['title' => 'Villa Job', 'service_id' => $service->id, 'sort_order' => 1]);
        BeforeAfterItem::factory()->create(['title' => 'Office Job', 'sort_order' => 2]);
        BeforeAfterItem::factory()->inactive()->create(['title' => 'Hidden Job']);

        $this->getJson('/api/v1/home/before-after?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.items.0.title', 'Villa Job')
            ->assertJsonPath('data.items.0.service.slug', $service->slug)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_home_reviews_return_published_reviews_only(): void
    {
        Review::factory()->published()->create(['comment' => 'Great work']);
        Review::factory()->create(['comment' => 'Pending comment']);
        Review::factory()->hidden()->create(['comment' => 'Hidden comment']);

        $response = $this->getJson('/api/v1/home/reviews')->assertOk();

        $this->assertSame(['Great work'], array_column($response->json('data.items'), 'comment'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_faq_section_returns_active_entries_ordered_by_sort_order(): void
    {
        Faq::factory()->create(['question' => 'Second question?', 'sort_order' => 2]);
        Faq::factory()->create(['question' => 'First question?', 'sort_order' => 1]);
        Faq::factory()->inactive()->create(['question' => 'Hidden question?']);

        $response = $this->getJson('/api/v1/home/faq')->assertOk();

        $this->assertSame(
            ['First question?', 'Second question?'],
            array_column($response->json('data.items'), 'question'),
        );
    }

    public function test_contact_exposes_only_the_approved_public_company_fields(): void
    {
        $this->seedCompanySettings();

        SystemSetting::query()->create([
            'category' => 'smtp',
            'key' => 'password',
            'value' => 'super-secret',
            'is_sensitive' => true,
        ]);

        $response = $this->getJson('/api/v1/home/contact')->assertOk();

        $this->assertSame(
            ['name', 'phone', 'email', 'whatsapp', 'address', 'working_hours', 'social'],
            array_keys($response->json('data')),
        );
        $this->assertSame('Fayadhowr Co', $response->json('data.name'));
        $this->assertSame('+252610000000', $response->json('data.phone'));
        $this->assertSame('08:00', $response->json('data.working_hours.open'));
        $this->assertSame('https://facebook.com/fayadhowr', $response->json('data.social.facebook'));

        $this->assertStringNotContainsString('smtp', $response->getContent());
        $this->assertStringNotContainsString('super-secret', $response->getContent());
        $this->assertStringNotContainsString('tax_id', $response->getContent());
    }

    public function test_home_aggregate_is_cached_and_invalidated_when_content_changes(): void
    {
        Faq::factory()->create(['question' => 'Original question?']);

        $this->getJson('/api/v1/home')->assertOk();

        // Raw insert bypasses model events: the cached aggregate must survive.
        DB::table('faqs')->insert([
            'question' => 'Sneaky question?',
            'answer' => 'Answer',
            'sort_order' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cached = $this->getJson('/api/v1/home')->assertOk();
        $this->assertCount(1, $cached->json('data.sections.faq'));

        // An Eloquent mutation invalidates through the HomeCacheInvalidator.
        Faq::factory()->create(['question' => 'Fresh question?']);

        $fresh = $this->getJson('/api/v1/home')->assertOk();
        $this->assertCount(3, $fresh->json('data.sections.faq'));
    }

    public function test_review_moderation_invalidates_the_home_cache(): void
    {
        $review = Review::factory()->create(['comment' => 'Awaiting moderation']);

        $this->getJson('/api/v1/home')->assertOk();

        $review->update(['status' => ReviewStatus::Published]);

        $response = $this->getJson('/api/v1/home')->assertOk();

        $this->assertSame(
            ['Awaiting moderation'],
            array_column($response->json('data.sections.reviews'), 'comment'),
        );
    }

    public function test_section_endpoints_reject_invalid_pagination(): void
    {
        $this->getJson('/api/v1/home/faq?per_page=500')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_all_home_endpoints_use_the_public_catalog_rate_limiter(): void
    {
        $routes = collect(Route::getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/home'));

        $this->assertCount(9, $routes);

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

        $favoritesCount = $overrides['favorites_count'] ?? null;
        unset($overrides['favorites_count']);

        $service = Service::query()->create(array_merge([
            'category_id' => $category->id,
            'name' => fake()->unique()->words(2, true),
            'slug' => fake()->unique()->slug(3),
            'currency' => 'USD',
            'requires_address' => true,
            'is_active' => true,
            'sort_order' => 0,
        ], $overrides));

        if ($favoritesCount !== null) {
            $service->forceFill(['favorites_count' => $favoritesCount])->save();
        }

        ServiceModeOption::query()->create([
            'service_id' => $service->id,
            'mode' => ServiceMode::OneTime,
            'is_active' => true,
        ]);

        return $service->refresh();
    }

    private function seedCompanySettings(): void
    {
        $values = [
            'name' => 'Fayadhowr Co',
            'email' => 'hello@fayadhowr.example',
            'phone' => '+252610000000',
            'website' => 'https://fayadhowr.example',
            'address' => 'Mogadishu, Somalia',
            'tax_id' => 'TAX-1234',
            'business_hours_open' => '08:00',
            'business_hours_close' => '18:00',
            'facebook' => 'https://facebook.com/fayadhowr',
            'instagram' => 'https://instagram.com/fayadhowr',
            'whatsapp' => '+252610000001',
        ];

        foreach ($values as $key => $value) {
            SystemSetting::query()->create([
                'category' => 'company',
                'key' => $key,
                'value' => $value,
                'is_sensitive' => false,
            ]);
        }
    }
}
