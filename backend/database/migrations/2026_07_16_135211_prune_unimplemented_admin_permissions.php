<?php

use App\Enums\AdminPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('permissions')
            ->whereNotIn('key', AdminPermission::values())
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Obsolete permission keys are not restored. Fresh installs seed from AdminPermission.
    }
};
