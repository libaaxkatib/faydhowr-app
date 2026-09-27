<?php

namespace App\Actions\Admin;

use App\Enums\AdminRole;
use App\Models\Permission;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads a role's currently-persisted permission set, independent of any
 * specific admin instance. This is the read counterpart to
 * UpdateRolePermissionsAction — without it, the Roles & Permissions UI has
 * no way to know a role's current permissions before editing them (the
 * existing `PUT roles/{role}/permissions` route is write-only/full-replace).
 */
class ListRolePermissionsAction
{
    /**
     * @return Collection<int, Permission>
     */
    public function handle(AdminRole $role): Collection
    {
        // Mirrors AdminPermissionResolver: Super Admin holds every permission
        // implicitly and has no persisted admin_role_permissions rows.
        if ($role === AdminRole::SuperAdmin) {
            return Permission::query()
                ->orderBy('group')
                ->orderBy('key')
                ->get();
        }

        $permissionIds = DB::table('admin_role_permissions')
            ->where('role', $role->value)
            ->pluck('permission_id');

        if ($permissionIds->isEmpty()) {
            return new Collection;
        }

        return Permission::query()
            ->whereIn('id', $permissionIds)
            ->orderBy('group')
            ->orderBy('key')
            ->get();
    }
}
