<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Database\Seeder;

class SystemSettingsSeeder extends Seeder
{
    /**
     * Seed every approved setting with its factory default. Idempotent:
     * existing rows (and their values) are left untouched.
     */
    public function run(): void
    {
        foreach (SettingsRegistry::definitions() as $category => $keys) {
            foreach ($keys as $key => $definition) {
                SystemSetting::query()->firstOrCreate(
                    ['category' => $category, 'key' => $key],
                    [
                        'value' => $definition['default'],
                        'default_value' => $definition['default'],
                        'is_sensitive' => $definition['sensitive'],
                    ],
                );
            }
        }
    }
}
