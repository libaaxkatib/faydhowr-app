<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Additive-only: widens the two Postgres CHECK constraints that enumerate
     * assignable admin roles, so hr_manager/hr_employee/marketing_manager/
     * marketing_employee become valid values. No existing row is touched.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE admins DROP CONSTRAINT admins_role_check');
        DB::statement(
            'ALTER TABLE admins ADD CONSTRAINT admins_role_check '
            ."CHECK (role IN ('super_admin', 'manager', 'sales', 'inventory', 'accountant', "
            ."'hr_manager', 'hr_employee', 'marketing_manager', 'marketing_employee'))",
        );

        DB::statement('ALTER TABLE admin_role_permissions DROP CONSTRAINT admin_role_permissions_role_check');
        DB::statement(
            'ALTER TABLE admin_role_permissions ADD CONSTRAINT admin_role_permissions_role_check '
            ."CHECK (role IN ('manager', 'sales', 'inventory', 'accountant', "
            ."'hr_manager', 'hr_employee', 'marketing_manager', 'marketing_employee'))",
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE admins DROP CONSTRAINT admins_role_check');
        DB::statement(
            'ALTER TABLE admins ADD CONSTRAINT admins_role_check '
            ."CHECK (role IN ('super_admin', 'manager', 'sales', 'inventory', 'accountant'))",
        );

        DB::statement('ALTER TABLE admin_role_permissions DROP CONSTRAINT admin_role_permissions_role_check');
        DB::statement(
            'ALTER TABLE admin_role_permissions ADD CONSTRAINT admin_role_permissions_role_check '
            ."CHECK (role IN ('manager', 'sales', 'inventory', 'accountant'))",
        );
    }
};
