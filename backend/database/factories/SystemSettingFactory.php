<?php

namespace Database\Factories;

use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SystemSetting>
 */
class SystemSettingFactory extends Factory
{
    protected $model = SystemSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category' => 'company',
            'key' => fake()->unique()->slug(2),
            'value' => fake()->word(),
            'default_value' => fake()->word(),
            'is_sensitive' => false,
            'updated_by' => null,
        ];
    }

    public function sensitive(): static
    {
        return $this->state(fn (): array => [
            'is_sensitive' => true,
        ]);
    }
}
