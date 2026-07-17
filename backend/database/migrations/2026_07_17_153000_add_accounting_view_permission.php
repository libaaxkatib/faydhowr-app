<?php

use App\Enums\AdminPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Fresh installs already receive accounting.view from the create_permissions_table
     * migration, which seeds all AdminPermission cases; this backfills existing databases.
     *
     * Accounting is a new module, so no role is granted the permission here; grants are
     * managed through the existing role permission API. Super Admin access is implicit.
     */
    public function up(): void
    {
        $exists = DB::table('permissions')
            ->where('key', AdminPermission::AccountingView->value)
            ->exists();

        if (! $exists) {
            $now = now();

            DB::table('permissions')->insert([
                'key' => AdminPermission::AccountingView->value,
                'name' => AdminPermission::AccountingView->label(),
                'group' => AdminPermission::AccountingView->group(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('permissions')
            ->where('key', AdminPermission::AccountingView->value)
            ->delete();
    }
};
