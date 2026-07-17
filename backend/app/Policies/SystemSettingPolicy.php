<?php

namespace App\Policies;

use App\Models\Admin;
use App\Policies\Concerns\AuthorizesSettingsAccess;

class SystemSettingPolicy
{
    use AuthorizesSettingsAccess;

    public function viewAny(Admin $admin): bool
    {
        return $this->canViewSettings($admin);
    }

    public function update(Admin $admin): bool
    {
        return $this->canManageSettings($admin);
    }

    public function restoreBackup(Admin $admin): bool
    {
        return $this->isSuperAdmin($admin);
    }
}
