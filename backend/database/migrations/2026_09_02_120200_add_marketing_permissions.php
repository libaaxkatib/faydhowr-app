<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * These 5 keys were inserted here, ahead of any route using them, which
     * violates AdminConsistencyPatchTest's "every AdminPermission case is
     * used by a route" invariant — a later migration
     * (2026_09_02_121000_remove_premature_marketing_permissions) deletes
     * them again. Kept as literal strings (not AdminPermission enum
     * references) because the corresponding enum cases were removed once
     * the ordering mistake was caught; they'll return, enum cases and
     * routes together, when Marketing is actually implemented.
     */
    public function up(): void
    {
        $now = now();

        $permissions = [
            ['key' => 'marketing.view', 'name' => 'View Marketing', 'group' => 'Marketing'],
            ['key' => 'marketing.manage', 'name' => 'Manage Marketing', 'group' => 'Marketing'],
            ['key' => 'marketing.assign', 'name' => 'Assign Marketing Work', 'group' => 'Marketing'],
            ['key' => 'marketing.commission.view', 'name' => 'View Marketing Commission', 'group' => 'Marketing'],
            ['key' => 'marketing.reports.view', 'name' => 'View Marketing Reports', 'group' => 'Marketing'],
        ];

        foreach ($permissions as $permission) {
            $exists = DB::table('permissions')->where('key', $permission['key'])->exists();

            if (! $exists) {
                DB::table('permissions')->insert([
                    ...$permission,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('permissions')
            ->whereIn('key', [
                'marketing.view',
                'marketing.manage',
                'marketing.assign',
                'marketing.commission.view',
                'marketing.reports.view',
            ])
            ->delete();
    }
};
