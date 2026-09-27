<?php

namespace Database\Factories;

use App\Models\SettingsBackup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SettingsBackup>
 */
class SettingsBackupFactory extends Factory
{
    protected $model = SettingsBackup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $snapshot = [
            'id' => 'backup-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)),
            'created_at' => now()->toIso8601String(),
            'created_by' => fake()->name(),
            'settings' => [],
            'branches' => [],
        ];

        return [
            'id' => $snapshot['id'],
            'snapshot' => $snapshot,
            'size_bytes' => strlen((string) json_encode($snapshot, JSON_PRETTY_PRINT)),
            'created_by' => $snapshot['created_by'],
            'created_at' => now(),
        ];
    }
}
