<?php

namespace Database\Factories;

use App\Models\ServiceMedia;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceMedia>
 */
class ServiceMediaFactory extends Factory
{
    protected $model = ServiceMedia::class;

    public function definition(): array
    {
        return [
            'media_type' => 'image',
            'url' => '/images/placeholders/services/'.$this->faker->unique()->slug(2).'.svg',
            'alt_text' => $this->faker->sentence(3),
            'sort_order' => 0,
            'is_primary' => false,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn (): array => ['is_primary' => true]);
    }
}
