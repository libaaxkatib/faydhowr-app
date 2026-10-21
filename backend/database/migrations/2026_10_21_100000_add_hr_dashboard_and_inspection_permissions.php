<?php

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registers the three permissions of the Phase 2 HR batch and keeps today's access exactly as it is:
 *
 *  - hr.dashboard.view  - the HR Dashboard used to be guarded by hr.view alone. It now has its own permission.
 *  - hr.inspections.view / hr.inspections.manage - Facility Inspections, complaints and their reports.
 *
 * Super Admin holds every permission implicitly (nothing is stored for it). The HR Manager ROLE receives each new permission
 * only when that role already holds the permission it was split from (dashboard <- hr.view, inspections view <- hr.view,
 * inspections manage <- hr.manage), so a role that has no baseline - or a deliberately narrower one - stays as it is and
 * nobody gains or loses access by the migration. Direct grants to individual admins are never touched. Every grant can be
 * removed on the Roles & Permissions screen. Idempotent; fresh installs already receive the catalogue rows from the
 * create_permissions_table migration, which seeds every AdminPermission case.
 */
return new class extends Migration
{
    /** @var array<string, string> new permission => base permission the HR Manager role must already hold */
    private const SPLIT_FROM = [
        'hr.dashboard.view' => 'hr.view',
        'hr.inspections.view' => 'hr.view',
        'hr.inspections.manage' => 'hr.manage',
    ];

    public function up(): void
    {
        $now = now();

        foreach ([AdminPermission::HrDashboardView, AdminPermission::HrInspectionsView, AdminPermission::HrInspectionsManage] as $permission) {
            $id = DB::table('permissions')->where('key', $permission->value)->value('id');

            if ($id === null) {
                $id = DB::table('permissions')->insertGetId([
                    'key' => $permission->value,
                    'name' => $permission->label(),
                    'group' => $permission->group(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $baseId = DB::table('permissions')->where('key', self::SPLIT_FROM[$permission->value])->value('id');
            $roleHoldsBase = $baseId !== null && DB::table('admin_role_permissions')
                ->where('role', AdminRole::HrManager->value)
                ->where('permission_id', $baseId)
                ->exists();
            $alreadyGranted = DB::table('admin_role_permissions')
                ->where('role', AdminRole::HrManager->value)
                ->where('permission_id', $id)
                ->exists();

            if ($roleHoldsBase && ! $alreadyGranted) {
                DB::table('admin_role_permissions')->insert([
                    'role' => AdminRole::HrManager->value,
                    'permission_id' => $id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Deleting the catalogue rows cascades to the role and admin grants of these three permissions only.
        DB::table('permissions')->whereIn('key', array_keys(self::SPLIT_FROM))->delete();
    }
};
