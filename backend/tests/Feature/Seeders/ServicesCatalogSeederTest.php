<?php

namespace Tests\Feature\Seeders;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceCoverageCity;
use App\Models\ServiceMedia;
use App\Models\ServiceModeOption;
use Database\Seeders\ServicesCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicesCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_provisions_the_official_catalog(): void
    {
        $this->seed(ServicesCatalogSeeder::class);

        $this->assertSame(3, ServiceCategory::query()->count());
        $this->assertSame(9, Service::query()->count());
        // Modes: 5 dual/one-time services (1–2 each) + Housekeeper (4 subtypes) + Monthly Cleaning Staff (6 subtypes).
        $this->assertSame(21, ServiceModeOption::query()->count());
        $this->assertSame(18, ServiceCoverageCity::query()->count());
        $this->assertSame(27, ServiceMedia::query()->count());

        $this->assertTrue(Service::query()->where('slug', 'deep-cleaning')->exists());

        $housekeeper = Service::query()->where('slug', 'housekeeper')->sole();
        $this->assertSame(
            ['full_time', 'part_time', 'live_in', 'live_out'],
            $housekeeper->modes()->orderBy('id')->pluck('subtype')->map->value->all(),
        );

        $primary = $housekeeper->media()->where('is_primary', true)->get();
        $this->assertCount(1, $primary);
        $this->assertSame('/images/placeholders/services/service-hero.svg', $primary->first()->url);
        $this->assertFileExists(public_path('images/placeholders/services/service-hero.svg'));
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(ServicesCatalogSeeder::class);
        $this->seed(ServicesCatalogSeeder::class);

        $this->assertSame(3, ServiceCategory::query()->count());
        $this->assertSame(9, Service::query()->count());
        $this->assertSame(21, ServiceModeOption::query()->count());
        $this->assertSame(18, ServiceCoverageCity::query()->count());
        $this->assertSame(27, ServiceMedia::query()->count());
    }

    public function test_seeded_catalog_is_served_by_the_public_api(): void
    {
        $this->seed(ServicesCatalogSeeder::class);

        $list = $this->getJson('/api/v1/services')
            ->assertOk()
            ->assertJsonPath('meta.total', 9);

        $thumbnail = $list->json('data.items.0.images.thumbnail');
        $this->assertStringStartsWith('http', $thumbnail);
        $this->assertStringEndsWith('/images/placeholders/services/service-hero.svg', $thumbnail);

        $this->getJson('/api/v1/service-categories')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->getJson('/api/v1/services/deep-cleaning')
            ->assertOk()
            ->assertJsonPath('data.slug', 'deep-cleaning')
            ->assertJsonPath('data.actions.book_now', true);
    }
}
