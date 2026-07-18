<?php

namespace Database\Factories;

use App\Enums\ServiceMode;
use App\Models\CustomerProfile;
use App\Models\Favorite;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceModeOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Favorite>
 */
class FavoriteFactory extends Factory
{
    protected $model = Favorite::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_profile_id' => CustomerProfile::factory(),
            'service_id' => null,
        ];
    }

    /**
     * Provision an active service (with an active mode) when the test does
     * not supply one explicitly.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Favorite $favorite): void {
            if ($favorite->service_id !== null) {
                return;
            }

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
                'is_active' => true,
                'sort_order' => 0,
            ]);

            ServiceModeOption::query()->create([
                'service_id' => $service->id,
                'mode' => ServiceMode::OneTime,
                'is_active' => true,
            ]);

            $favorite->service_id = $service->id;
        });
    }
}
