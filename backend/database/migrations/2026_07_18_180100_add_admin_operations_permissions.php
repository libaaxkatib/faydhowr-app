<?php

use App\Enums\AdminPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fresh installs already receive these keys from create_permissions_table
     * which seeds all AdminPermission cases; this backfills existing databases.
     */
    public function up(): void
    {
        $now = now();

        $permissions = [
            AdminPermission::PaymentsView,
            AdminPermission::PaymentsConfirm,
            AdminPermission::BookingsView,
            AdminPermission::BookingsManage,
            AdminPermission::StoreOrdersView,
            AdminPermission::StoreOrdersManage,
        ];

        foreach ($permissions as $permission) {
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

    public function down(): void
    {
        DB::table('permissions')
            ->whereIn('key', [
                AdminPermission::PaymentsView->value,
                AdminPermission::PaymentsConfirm->value,
                AdminPermission::BookingsView->value,
                AdminPermission::BookingsManage->value,
                AdminPermission::StoreOrdersView->value,
                AdminPermission::StoreOrdersManage->value,
            ])
            ->delete();
    }
};
