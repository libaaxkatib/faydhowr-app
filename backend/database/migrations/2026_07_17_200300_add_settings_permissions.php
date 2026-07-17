<?php

use App\Enums\AdminPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Fresh installs already receive settings.view and settings.manage from the
     * create_permissions_table migration, which seeds all AdminPermission cases;
     * this backfills existing databases.
     *
     * Settings is a new module, so no role is granted the permissions here; grants
     * are managed through the existing role permission API. Super Admin access is
     * implicit.
     */
    public function up(): void
    {
        $now = now();

        foreach ([AdminPermission::SettingsView, AdminPermission::SettingsManage] as $permission) {
            $exists = DB::table('permissions')
                ->where('key', $permission->value)
                ->exists();

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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('permissions')
            ->whereIn('key', [AdminPermission::SettingsView->value, AdminPermission::SettingsManage->value])
            ->delete();
    }
};
