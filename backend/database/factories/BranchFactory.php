<?php

namespace Database\Factories;

use App\Enums\Settings\BranchStatus;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $city = fake()->unique()->city();

        return [
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'name' => $city,
            'city' => $city,
            'status' => BranchStatus::Active,
            'is_default' => false,
            'activated_at' => null,
            'activated_by' => null,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (): array => [
            'status' => BranchStatus::Active,
            'is_default' => true,
        ]);
    }

    public function comingSoon(): static
    {
        return $this->state(fn (): array => [
            'status' => BranchStatus::ComingSoon,
            'is_default' => false,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'status' => BranchStatus::Inactive,
            'is_default' => false,
        ]);
    }
}
