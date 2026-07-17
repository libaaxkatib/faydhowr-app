<?php

use App\Enums\AdminPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Fresh installs already receive reports.view from the create_permissions_table
     * migration, which seeds all AdminPermission cases; this backfills existing databases.
     */
    public function up(): void
    {
        $exists = DB::table('permissions')
            ->where('key', AdminPermission::ReportsView->value)
            ->exists();

        if (! $exists) {
            $now = now();

            DB::table('permissions')->insert([
                'key' => AdminPermission::ReportsView->value,
                'name' => AdminPermission::ReportsView->label(),
                'group' => AdminPermission::ReportsView->group(),
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
            ->where('key', AdminPermission::ReportsView->value)
            ->delete();
    }
};
