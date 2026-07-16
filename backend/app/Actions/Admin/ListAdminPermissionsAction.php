<?php

namespace App\Actions\Admin;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Permission;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ListAdminPermissionsAction
{
    /**
     * @return array{
     *     role: string,
     *     role_permissions: Collection<int, Permission>,
     *     direct_permissions: Collection<int, Permission>,
     *     effective_permissions: Collection<int, Permission>
     * }
     */
    public function handle(Admin $admin): array
    {
        if ($admin->role === AdminRole::SuperAdmin) {
            $allPermissions = Permission::query()
                ->orderBy('group')
                ->orderBy('key')
                ->get();

            return [
                'role' => $admin->role->value,
                'role_permissions' => new Collection,
                'direct_permissions' => new Collection,
                'effective_permissions' => $allPermissions,
            ];
        }

        $rolePermissions = $this->permissionsForRole($admin->role);
        $directPermissions = $this->directPermissionsForAdmin($admin);

        $effectivePermissions = $rolePermissions
            ->concat($directPermissions)
            ->unique('id')
            ->sortBy([
                ['group', 'asc'],
                ['key', 'asc'],
            ])
            ->values();

        return [
            'role' => $admin->role->value,
            'role_permissions' => $rolePermissions,
            'direct_permissions' => $directPermissions,
            'effective_permissions' => $effectivePermissions,
        ];
    }

    /**
     * @return Collection<int, Permission>
     */
    private function permissionsForRole(AdminRole $role): Collection
    {
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

    /**
     * @return Collection<int, Permission>
     */
    private function directPermissionsForAdmin(Admin $admin): Collection
    {
        $permissionIds = DB::table('admin_permissions')
            ->where('admin_id', $admin->id)
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
