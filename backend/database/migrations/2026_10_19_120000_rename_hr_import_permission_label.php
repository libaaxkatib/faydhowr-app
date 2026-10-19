<?php

use App\Enums\AdminPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * hr.import is now the HR Manager's dedicated import permission (it can be granted to that role only), so the catalogue label
 * no longer says "Super Admin only". Only the display name changes: no grant is created, changed or removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')
            ->where('key', AdminPermission::HrImport->value)
            ->update(['name' => AdminPermission::HrImport->label(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('permissions')
            ->where('key', AdminPermission::HrImport->value)
            ->update(['name' => 'Import HR Data (Super Admin only)', 'updated_at' => now()]);
    }
};
