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
            AdminPermission::CustomersView,
            AdminPermission::CustomersCreate,
            AdminPermission::CustomersUpdate,
            AdminPermission::CustomersDelete,
            AdminPermission::CustomersRestore,
            AdminPermission::CustomersNotes,
            AdminPermission::CustomersAttachments,
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
                AdminPermission::CustomersView->value,
                AdminPermission::CustomersCreate->value,
                AdminPermission::CustomersUpdate->value,
                AdminPermission::CustomersDelete->value,
                AdminPermission::CustomersRestore->value,
                AdminPermission::CustomersNotes->value,
                AdminPermission::CustomersAttachments->value,
            ])
            ->delete();
    }
};
