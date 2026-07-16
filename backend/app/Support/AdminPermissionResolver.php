<?php

namespace App\Support;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminPermissionResolver
{
    /**
     * @return list<string>
     */
    public function keysFor(Admin $admin, ?Request $request = null): array
    {
        $request ??= request();
        $cacheKey = 'admin_effective_permission_keys_'.$admin->id;

        if ($request->attributes->has($cacheKey)) {
            /** @var list<string> $cached */
            $cached = $request->attributes->get($cacheKey);

            return $cached;
        }

        $keys = $admin->role === AdminRole::SuperAdmin
            ? AdminPermission::values()
            : $this->resolveHybridPermissionKeys($admin);

        $request->attributes->set($cacheKey, $keys);

        return $keys;
    }

    public function has(Admin $admin, string $permission, ?Request $request = null): bool
    {
        return in_array($permission, $this->keysFor($admin, $request), true);
    }

    /**
     * @return list<string>
     */
    private function resolveHybridPermissionKeys(Admin $admin): array
    {
        $roleKeys = DB::table('admin_role_permissions')
            ->join('permissions', 'permissions.id', '=', 'admin_role_permissions.permission_id')
            ->where('admin_role_permissions.role', $admin->role->value)
            ->pluck('permissions.key');

        $directKeys = DB::table('admin_permissions')
            ->join('permissions', 'permissions.id', '=', 'admin_permissions.permission_id')
            ->where('admin_permissions.admin_id', $admin->id)
            ->pluck('permissions.key');

        return $roleKeys
            ->merge($directKeys)
            ->unique()
            ->values()
            ->all();
    }
}
