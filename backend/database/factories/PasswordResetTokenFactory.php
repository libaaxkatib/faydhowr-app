<?php

namespace Database\Factories;

use App\Models\PasswordResetToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<PasswordResetToken>
 */
class PasswordResetTokenFactory extends Factory
{
    protected $model = PasswordResetToken::class;

    public function definition(): array
    {
        return [
            'subject_type' => PasswordResetToken::SUBJECT_USER,
            'subject_id' => 1,
            'token_hash' => Hash::make('token'),
            'expires_at' => now()->addHour(),
            'used_at' => null,
            'created_at' => now(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subMinute(),
            'created_at' => now()->subHours(2),
        ]);
    }

    public function used(): static
    {
        return $this->state(fn (): array => [
            'used_at' => now(),
        ]);
    }
}
