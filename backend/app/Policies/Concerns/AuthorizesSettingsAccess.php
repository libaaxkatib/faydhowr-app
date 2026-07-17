<?php

namespace App\Policies\Concerns;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Support\AdminPermissionResolver;

/**
 * Shared checks for the Settings module policies: read abilities require
 * settings.view, write abilities require settings.manage, and restricted
 * actions (branch activation, default branch, backup restore) are Super
 * Admin only.
 */
trait AuthorizesSettingsAccess
{
    public function __construct(private AdminPermissionResolver $permissions) {}

    protected function canViewSettings(Admin $admin): bool
    {
        return $this->permissions->has($admin, AdminPermission::SettingsView->value);
    }

    protected function canManageSettings(Admin $admin): bool
    {
        return $this->permissions->has($admin, AdminPermission::SettingsManage->value);
    }

    protected function isSuperAdmin(Admin $admin): bool
    {
        return $admin->role === AdminRole::SuperAdmin;
    }
}
