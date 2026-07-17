<?php

namespace Database\Factories;

use App\Models\Admin;
use App\Models\SettingsAuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SettingsAuditLog>
 */
class SettingsAuditLogFactory extends Factory
{
    protected $model = SettingsAuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category' => 'company',
            'key' => fake()->slug(2),
            'old_value' => fake()->word(),
            'new_value' => fake()->word(),
            'changed_by' => Admin::factory(),
            'changed_at' => now(),
            'ip_address' => fake()->ipv4(),
        ];
    }
}
