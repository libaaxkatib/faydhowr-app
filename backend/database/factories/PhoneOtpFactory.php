<?php

namespace Database\Factories;

use App\Enums\Auth\OtpPurpose;
use App\Models\PhoneOtp;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<PhoneOtp>
 */
class PhoneOtpFactory extends Factory
{
    protected $model = PhoneOtp::class;

    public function definition(): array
    {
        return [
            'phone' => '+2526'.$this->faker->unique()->numerify('#######'),
            'purpose' => OtpPurpose::Login,
            'otp_hash' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
            'consumed_at' => null,
            'invalidated_at' => null,
            'created_at' => now(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(6),
        ]);
    }

    public function consumed(): static
    {
        return $this->state(fn (): array => [
            'consumed_at' => now(),
        ]);
    }

    public function passwordReset(): static
    {
        return $this->state(fn (): array => [
            'purpose' => OtpPurpose::PasswordReset,
        ]);
    }
}
