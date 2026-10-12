<?php

use App\Enums\AdminPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registers the hr.import permission in the catalogue so it can be reported to the Super Admin as one of
 * their own effective permissions. It is reserved for Super Admin (AdminPermission::isSuperAdminOnly):
 * nothing is granted here, the Roles & Permissions screen does not offer it, the grant endpoints refuse
 * it and the resolver ignores it for every other role. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('permissions')->where('key', AdminPermission::HrImport->value)->exists()) {
            return;
        }

        $now = now();

        DB::table('permissions')->insert([
            'key' => AdminPermission::HrImport->value,
            'name' => AdminPermission::HrImport->label(),
            'group' => AdminPermission::HrImport->group(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Also remove any (unsupported) grants of this permission before deleting the catalogue row.
        $id = DB::table('permissions')->where('key', AdminPermission::HrImport->value)->value('id');

        if ($id === null) {
            return;
        }

        DB::table('admin_role_permissions')->where('permission_id', $id)->delete();
        DB::table('admin_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
    }
};
