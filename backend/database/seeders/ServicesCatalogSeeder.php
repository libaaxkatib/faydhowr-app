<?php

namespace Database\Seeders;

use App\Enums\ServiceMode;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Official V1 Services Catalog Seeder (SRS FR-010B, API Design §6.2).
 *
 * Provisions the approved catalog — categories, the nine official services,
 * their modes/subtypes, coverage cities, and placeholder media — and is
 * idempotent: re-running updates in place without duplicating rows. The
 * catalog is seeder-managed until Admin Services CRUD (Sprint 29).
 *
 * Placeholder media are local application assets (public/images/placeholders);
 * no external placeholder providers are used. starting_from_price may be null
 * until business pricing is configured — the field is optional by design.
 */
class ServicesCatalogSeeder extends Seeder
{
    private const array COVERAGE_CITIES = ['Mogadishu', 'Hargeisa'];

    public function run(): void
    {
        $categories = $this->seedCategories();

        foreach ($this->officialCatalog() as $index => $definition) {
            $service = Service::query()->updateOrCreate(
                ['slug' => Str::slug($definition['name'])],
                [
                    'category_id' => $categories[$definition['category']]->id,
                    'name' => $definition['name'],
                    'short_description' => $definition['short_description'],
                    'description' => $definition['description'],
                    'currency' => 'USD',
                    'requires_address' => true,
                    'is_active' => true,
                    'sort_order' => $index + 1,
                ],
            );

            foreach ($definition['modes'] as [$mode, $subtype]) {
                $service->modes()->firstOrCreate(
                    ['mode' => $mode->value, 'subtype' => $subtype],
                    ['is_active' => true],
                );
            }

            foreach (self::COVERAGE_CITIES as $city) {
                $service->coverageCities()->firstOrCreate(
                    ['city' => $city],
                    ['is_active' => true],
                );
            }

            $this->seedPlaceholderMedia($service);
        }
    }

    /**
     * @return array<string, ServiceCategory>
     */
    private function seedCategories(): array
    {
        $definitions = [
            'cleaning' => ['name' => 'Cleaning Services', 'sort_order' => 1],
            'pest_control' => ['name' => 'Pest Control Services', 'sort_order' => 2],
            'staffing' => ['name' => 'Staffing Services', 'sort_order' => 3],
        ];

        $categories = [];

        foreach ($definitions as $key => $definition) {
            $categories[$key] = ServiceCategory::query()->updateOrCreate(
                ['slug' => Str::slug($definition['name'])],
                [
                    'name' => $definition['name'],
                    'sort_order' => $definition['sort_order'],
                    'is_active' => true,
                ],
            );
        }

        return $categories;
    }

    private function seedPlaceholderMedia(Service $service): void
    {
        $placeholders = [
            ['url' => '/images/placeholders/services/service-hero.svg', 'is_primary' => true, 'sort_order' => 0],
            ['url' => '/images/placeholders/services/service-gallery-1.svg', 'is_primary' => false, 'sort_order' => 1],
            ['url' => '/images/placeholders/services/service-gallery-2.svg', 'is_primary' => false, 'sort_order' => 2],
        ];

        foreach ($placeholders as $placeholder) {
            $service->media()->updateOrCreate(
                ['url' => $placeholder['url']],
                [
                    'media_type' => 'image',
                    'alt_text' => $service->name,
                    'sort_order' => $placeholder['sort_order'],
                    'is_primary' => $placeholder['is_primary'],
                ],
            );
        }
    }

    /**
     * The approved official V1 catalog (API Design §6.2, frozen).
     *
     * @return list<array{
     *     name: string,
     *     category: string,
     *     short_description: string,
     *     description: string,
     *     modes: list<array{0: ServiceMode, 1: ?string}>
     * }>
     */
    private function officialCatalog(): array
    {
        $housekeeperSubtypes = ['full_time', 'part_time', 'live_in', 'live_out'];
        $staffSubtypes = ['office', 'hotel', 'restaurant', 'school', 'hospital_clinic', 'other_business'];

        return [
            [
                'name' => 'Deep Cleaning',
                'category' => 'cleaning',
                'short_description' => 'Thorough top-to-bottom cleaning for homes and offices.',
                'description' => 'Comprehensive deep cleaning covering all rooms, surfaces, fixtures, and hard-to-reach areas, delivered by trained Fayadhowr teams with professional equipment.',
                'modes' => [[ServiceMode::OneTime, null], [ServiceMode::MonthlyContract, null]],
            ],
            [
                'name' => 'Pest Control',
                'category' => 'pest_control',
                'short_description' => 'Safe, effective treatment against household and commercial pests.',
                'description' => 'Inspection and targeted treatment for insects and rodents using approved products, with prevention guidance for lasting results.',
                'modes' => [[ServiceMode::OneTime, null], [ServiceMode::MonthlyContract, null]],
            ],
            [
                'name' => 'Carpet Cleaning',
                'category' => 'cleaning',
                'short_description' => 'Professional carpet washing, stain removal, and drying.',
                'description' => 'Deep carpet shampooing and extraction that removes embedded dirt, stains, and odors while protecting carpet fibers.',
                'modes' => [[ServiceMode::OneTime, null]],
            ],
            [
                'name' => 'Sofa & Chair Cleaning',
                'category' => 'cleaning',
                'short_description' => 'Upholstery cleaning that restores furniture freshness.',
                'description' => 'Specialized upholstery cleaning for sofas, chairs, and cushions, removing stains, dust mites, and odors safely.',
                'modes' => [[ServiceMode::OneTime, null]],
            ],
            [
                'name' => 'Post Construction Cleaning',
                'category' => 'cleaning',
                'short_description' => 'Complete cleanup after construction or renovation work.',
                'description' => 'Removal of construction dust, debris, paint spots, and residues to make newly built or renovated spaces move-in ready.',
                'modes' => [[ServiceMode::OneTime, null]],
            ],
            [
                'name' => 'Window Cleaning',
                'category' => 'cleaning',
                'short_description' => 'Streak-free window and glass cleaning, inside and out.',
                'description' => 'Professional cleaning of windows, glass facades, and frames for homes and commercial buildings.',
                'modes' => [[ServiceMode::OneTime, null], [ServiceMode::MonthlyContract, null]],
            ],
            [
                'name' => 'Fumigation Services',
                'category' => 'pest_control',
                'short_description' => 'Full-space fumigation for severe infestations.',
                'description' => 'Certified fumigation treatments for homes, warehouses, and commercial premises, following strict safety protocols.',
                'modes' => [[ServiceMode::OneTime, null], [ServiceMode::MonthlyContract, null]],
            ],
            [
                'name' => 'Housekeeper',
                'category' => 'staffing',
                'short_description' => 'Reliable housekeeping staff on monthly contracts.',
                'description' => 'Vetted, trained housekeepers available full-time, part-time, live-in, or live-out under a monthly contract managed by Fayadhowr.',
                'modes' => array_map(
                    fn (string $subtype): array => [ServiceMode::MonthlyContract, $subtype],
                    $housekeeperSubtypes,
                ),
            ],
            [
                'name' => 'Monthly Cleaning Staff',
                'category' => 'staffing',
                'short_description' => 'Dedicated cleaning staff for businesses and institutions.',
                'description' => 'Professional cleaning staff assigned to offices, hotels, restaurants, schools, hospitals and clinics, and other businesses on monthly contracts.',
                'modes' => array_map(
                    fn (string $subtype): array => [ServiceMode::MonthlyContract, $subtype],
                    $staffSubtypes,
                ),
            ],
        ];
    }
}
