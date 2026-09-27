<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Additive-only, mirrors 2026_09_02_120000_widen_admin_roles_for_hr_and_marketing:
     * widens the two Postgres CHECK constraints that enumerate assignable admin
     * roles so mobile_app_manager becomes a valid value. No existing row is
     * touched, and no other value is removed — hr_employee/marketing_employee
     * remain valid database values even though the Web Panel's Phase 1
     * "create admin" UI no longer offers them for new assignments.
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
            ."'hr_manager', 'hr_employee', 'marketing_manager', 'marketing_employee', 'mobile_app_manager'))",
        );

        DB::statement('ALTER TABLE admin_role_permissions DROP CONSTRAINT admin_role_permissions_role_check');
        DB::statement(
            'ALTER TABLE admin_role_permissions ADD CONSTRAINT admin_role_permissions_role_check '
            ."CHECK (role IN ('manager', 'sales', 'inventory', 'accountant', "
            ."'hr_manager', 'hr_employee', 'marketing_manager', 'marketing_employee', 'mobile_app_manager'))",
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
};
