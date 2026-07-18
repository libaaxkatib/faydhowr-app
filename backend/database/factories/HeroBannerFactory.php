<?php

namespace Database\Factories;

use App\Enums\Home\HeroBannerActionType;
use App\Models\HeroBanner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HeroBanner>
 */
class HeroBannerFactory extends Factory
{
    protected $model = HeroBanner::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'subtitle' => fake()->optional()->sentence(6),
            'image_url' => fake()->imageUrl(),
            'action_type' => HeroBannerActionType::None,
            'action_reference' => null,
            'sort_order' => 0,
            'is_active' => true,
            'starts_at' => null,
            'ends_at' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function scheduled(?string $startsAt, ?string $endsAt): static
    {
        return $this->state(fn (): array => [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }

    public function withUrlAction(): static
    {
        return $this->state(fn (): array => [
            'action_type' => HeroBannerActionType::Url,
            'action_reference' => fake()->url(),
        ]);
    }
}
