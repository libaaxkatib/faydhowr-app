<?php

namespace Database\Seeders;

use App\Enums\Settings\BranchStatus;
use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    /**
     * Seed the V1 branches: Mogadishu (operational default) and Hargeisa
     * (visible as COMING_SOON, not selectable).
     */
    public function run(): void
    {
        Branch::query()->firstOrCreate(
            ['code' => 'MGQ'],
            [
                'name' => 'Mogadishu',
                'city' => 'Mogadishu',
                'status' => BranchStatus::Active,
                'is_default' => true,
            ],
        );

        Branch::query()->firstOrCreate(
            ['code' => 'HGA'],
            [
                'name' => 'Hargeisa',
                'city' => 'Hargeisa',
                'status' => BranchStatus::ComingSoon,
                'is_default' => false,
            ],
        );
    }
}
