<?php

namespace Database\Seeders;

use App\Enums\AdminRole;
use App\Enums\AdminStatus;
use App\Models\Admin;
use Illuminate\Database\Seeder;

/**
 * Creates ONE local-development admin account for manually exercising the
 * Admin API / Web Panel. Refuses to run outside app()->environment('local')
 * so it can never touch a staging/production database.
 *
 * Intentionally NOT registered in DatabaseSeeder::run() — run it explicitly:
 *   php artisan db:seed --class=DevAdminSeeder
 *
 * Uses Admin::updateOrCreate() so re-running is idempotent (updates the same
 * row rather than creating duplicates) and touches nothing but this one
 * account. Goes through the real Admin model, so password hashing and the
 * role/status enum casts are identical to how a real admin is created.
 */
class DevAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->error('DevAdminSeeder only runs when APP_ENV=local. Aborting.');

            return;
        }

        Admin::updateOrCreate(
            ['email' => 'admin@fayadhowr.local'],
            [
                'full_name' => 'Fayadhowr Local Admin',
                'phone' => '+252610000000',
                'password' => 'Fyh-WebPanel!Dev2026#Secure',
                'role' => AdminRole::SuperAdmin,
                'status' => AdminStatus::Active,
            ],
        );

        $this->command?->info('Local dev admin ready: admin@fayadhowr.local');
    }
}
