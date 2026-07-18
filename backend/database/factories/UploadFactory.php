<?php

namespace Database\Factories;

use App\Enums\Upload\UploadMediaType;
use App\Models\Upload;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Upload>
 */
class UploadFactory extends Factory
{
    protected $model = Upload::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'disk' => 'local',
            'path' => 'uploads/'.$this->faker->unique()->uuid().'.jpg',
            'original_name' => $this->faker->word().'.jpg',
            'media_type' => UploadMediaType::Image,
            'mime_type' => 'image/jpeg',
            'file_size_bytes' => $this->faker->numberBetween(1_000, 500_000),
            'attached_at' => null,
            'expires_at' => now()->addDays(7),
        ];
    }

    public function attached(): static
    {
        return $this->state(fn (): array => [
            'attached_at' => now(),
            'expires_at' => null,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function document(): static
    {
        return $this->state(fn (): array => [
            'path' => 'uploads/'.$this->faker->unique()->uuid().'.pdf',
            'original_name' => $this->faker->word().'.pdf',
            'media_type' => UploadMediaType::Document,
            'mime_type' => 'application/pdf',
        ]);
    }
}
