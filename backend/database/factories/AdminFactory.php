<?php

namespace Database\Factories;

use App\Enums\AdminRole;
use App\Enums\AdminStatus;
use App\Models\Admin;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Admin>
 */
class AdminFactory extends Factory
{
    protected $model = Admin::class;

    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->e164PhoneNumber(),
            'password' => static::$password ??= 'password',
            'role' => AdminRole::Manager,
            'status' => AdminStatus::Active,
            'last_login_at' => null,
            'remember_token' => Str::random(10),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'status' => AdminStatus::Inactive,
        ]);
    }

    public function superAdmin(): static
    {
        return $this->state(fn (): array => [
            'role' => AdminRole::SuperAdmin,
        ]);
    }
}
