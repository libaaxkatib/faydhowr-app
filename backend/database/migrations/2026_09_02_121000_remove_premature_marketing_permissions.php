<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The Marketing permission keys were inserted a migration too early —
     * added to the `permissions` table before any route actually used them,
     * which violates this app's own AdminConsistencyPatchTest invariant
     * (every AdminPermission case must be used by at least one protected
     * route). Removing the orphaned rows now; they'll be re-added by a
     * proper migration once Marketing routes are actually implemented
     * (Phase 5 of the HRM+Marketing plan). Additive-only: this is a new
     * migration, not an edit to the one that inserted them.
     */
    public function up(): void
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

    public function down(): void
    {
        // Intentionally irreversible as a no-op: these rows will be recreated
        // by the proper add_marketing_permissions migration in Phase 5.
    }
};
