<?php

use App\Enums\AdminPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Re-adds the 5 Marketing permission keys removed in
     * 2026_09_02_121000_remove_premature_marketing_permissions — this time
     * alongside the actual Marketing routes that use them, satisfying
     * AdminConsistencyPatchTest's invariant.
     */
    public function up(): void
    {
        $now = now();

        $permissions = [
            AdminPermission::MarketingView,
            AdminPermission::MarketingManage,
            AdminPermission::MarketingAssign,
            AdminPermission::MarketingCommissionView,
            AdminPermission::MarketingReportsView,
        ];

        foreach ($permissions as $permission) {
            $exists = DB::table('permissions')->where('key', $permission->value)->exists();

            if (! $exists) {
                DB::table('permissions')->insert([
                    'key' => $permission->value,
                    'name' => $permission->label(),
                    'group' => $permission->group(),
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
                AdminPermission::MarketingView->value,
                AdminPermission::MarketingManage->value,
                AdminPermission::MarketingAssign->value,
                AdminPermission::MarketingCommissionView->value,
                AdminPermission::MarketingReportsView->value,
            ])
            ->delete();
    }
};
