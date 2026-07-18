<?php

namespace Database\Factories;

use App\Models\BeforeAfterItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BeforeAfterItem>
 */
class BeforeAfterItemFactory extends Factory
{
    protected $model = BeforeAfterItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_id' => null,
            'title' => fake()->sentence(3),
            'before_image_url' => fake()->imageUrl(),
            'after_image_url' => fake()->imageUrl(),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
