<?php

namespace App\Actions\Admin;

use App\Enums\AdminPermission;
use App\Models\Permission;
use Illuminate\Database\Eloquent\Collection;

class ListPermissionsAction
{
    /**
     * @return Collection<int, Permission>
     */
    public function handle(): Collection
    {
        // This is the catalogue the Roles & Permissions screen offers for granting, so permissions that are
        // reserved for Super Admin (hr.import) are not listed.
        return Permission::query()
            ->whereNotIn('key', AdminPermission::superAdminOnlyValues())
            ->orderBy('group')
            ->orderBy('key')
            ->get();
    }
}
