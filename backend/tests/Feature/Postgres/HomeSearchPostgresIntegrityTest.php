<?php

namespace Tests\Feature\Postgres;

use App\Enums\ServiceMode;
use App\Models\HeroBanner;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceModeOption;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HomeSearchPostgresIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This integration test requires PostgreSQL.');
        }
    }

    public function test_hero_banner_action_type_check_constraint_is_enforced(): void
    {
        $this->expectException(QueryException::class);

        DB::table('hero_banners')->insert([
            'title' => 'Bad Banner',
            'image_url' => 'https://cdn.example.com/x.jpg',
            'action_type' => 'bogus',
            'sort_order' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_hero_banner_action_reference_consistency_is_enforced(): void
    {
        $this->expectException(QueryException::class);

        DB::table('hero_banners')->insert([
            'title' => 'Bad Banner',
            'image_url' => 'https://cdn.example.com/x.jpg',
            'action_type' => 'service',
            'action_reference' => null,
            'sort_order' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_hero_banner_schedule_check_constraint_is_enforced(): void
    {
        $this->expectException(QueryException::class);

        HeroBanner::factory()->scheduled(
            now()->addWeek()->toDateTimeString(),
            now()->toDateTimeString(),
        )->create();
    }

    public function test_pg_trgm_gin_indexes_exist_on_searched_columns(): void
    {
        $extension = DB::selectOne("SELECT COUNT(*) AS total FROM pg_extension WHERE extname = 'pg_trgm'");
        $this->assertSame(1, (int) $extension->total);

        $expected = [
            'services_name_trgm_index',
            'services_short_description_trgm_index',
            'products_name_trgm_index',
            'products_description_trgm_index',
        ];

        $indexes = collect(DB::select(
            'SELECT indexname FROM pg_indexes WHERE indexname = ANY(?::text[])',
            ['{'.implode(',', $expected).'}'],
        ))->pluck('indexname')->all();

        $this->assertEqualsCanonicalizing($expected, $indexes);
    }

    public function test_search_matches_case_insensitively_through_ilike(): void
    {
        $category = ServiceCategory::query()->create([
            'name' => 'Cleaning',
            'slug' => 'cleaning',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $service = Service::query()->create([
            'category_id' => $category->id,
            'name' => 'DEEP CLEANING',
            'slug' => 'deep-cleaning',
            'currency' => 'USD',
            'requires_address' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        ServiceModeOption::query()->create([
            'service_id' => $service->id,
            'mode' => ServiceMode::OneTime,
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/search?q=deep+cleaning&type=service')
            ->assertOk()
            ->assertJsonPath('data.services.items.0.name', 'DEEP CLEANING');
    }
}
