<?php

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Fresh installs already receive dashboard.view from the create_permissions_table
     * migration, which seeds all AdminPermission cases; this backfills existing databases.
     *
     * Every operations role could previously access the dashboard without a dedicated
     * permission, so the permission is granted to all of them to preserve behavior.
     * Super Admin permissions are implicit and never persisted.
     */
    public function up(): void
    {
        $permissionId = DB::table('permissions')
            ->where('key', AdminPermission::DashboardView->value)
            ->value('id');

        if ($permissionId === null) {
            $now = now();

            $permissionId = DB::table('permissions')->insertGetId([
                'key' => AdminPermission::DashboardView->value,
                'name' => AdminPermission::DashboardView->label(),
                'group' => AdminPermission::DashboardView->group(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $operationsRoles = [
            AdminRole::Manager,
            AdminRole::Sales,
            AdminRole::Inventory,
            AdminRole::Accountant,
        ];

        $now = now();

        foreach ($operationsRoles as $role) {
            $exists = DB::table('admin_role_permissions')
                ->where('role', $role->value)
                ->where('permission_id', $permissionId)
                ->exists();

            if (! $exists) {
                DB::table('admin_role_permissions')->insert([
                    'role' => $role->value,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * Deleting the permission cascades to admin_role_permissions rows.
     */
    public function down(): void
    {
        DB::table('permissions')
            ->where('key', AdminPermission::DashboardView->value)
            ->delete();
    }
};
