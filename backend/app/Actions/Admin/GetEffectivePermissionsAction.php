<?php

namespace App\Actions\Admin;

use App\Models\Admin;
use App\Models\Permission;
use App\Support\AdminPermissionResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * Returns the CALLING admin's own effective permissions (role-derived plus
 * any direct overrides, or every permission implicitly for Super Admin).
 *
 * This is the read counterpart the frontend was missing: AdminPermissionResolver
 * already computes this correctly for authorization, but the only existing way
 * to see the result was `GET /admin/admins/{admin}/permissions`, which requires
 * `roles.manage` - a permission most non-Super-Admin accounts don't have, so
 * they could never introspect their own access. This reuses the exact same
 * resolver as the source of truth; no new resolution logic is introduced.
 */
class GetEffectivePermissionsAction
{
    public function __construct(private AdminPermissionResolver $resolver) {}

    /**
     * @return Collection<int, Permission>
     */
    public function handle(Admin $admin, Request $request): Collection
    {
        $keys = $this->resolver->keysFor($admin, $request);

        if ($keys === []) {
            return new Collection;
        }

        return Permission::query()
            ->whereIn('key', $keys)
            ->orderBy('group')
            ->orderBy('key')
            ->get();
    }
}
