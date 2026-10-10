<?php

use App\Enums\AdminPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Payroll permissions are separate from hr.manage so duties can be split:
 * view, prepare/review, and approve/finalize/correct. Super Admin receives
 * all permissions automatically; every other role must be granted these
 * explicitly through the role/permission screen (nothing is granted here).
 */
return new class extends Migration
{
    /** @return list<AdminPermission> */
    private function permissions(): array
    {
        return [
            AdminPermission::HrPayrollView,
            AdminPermission::HrPayrollManage,
            AdminPermission::HrPayrollApprove,
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->permissions() as $permission) {
            if (DB::table('permissions')->where('key', $permission->value)->exists()) {
                continue;
            }

            DB::table('permissions')->insert([
                'key' => $permission->value,
                'name' => $permission->label(),
                'group' => $permission->group(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('permissions')
            ->whereIn('key', array_map(fn (AdminPermission $p): string => $p->value, $this->permissions()))
            ->delete();
    }
};
