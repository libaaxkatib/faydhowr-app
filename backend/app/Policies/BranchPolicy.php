<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\Branch;
use App\Policies\Concerns\AuthorizesSettingsAccess;

class BranchPolicy
{
    use AuthorizesSettingsAccess;

    public function viewAny(Admin $admin): bool
    {
        return $this->canViewSettings($admin);
    }

    public function view(Admin $admin, Branch $branch): bool
    {
        return $this->canViewSettings($admin);
    }

    public function activate(Admin $admin, Branch $branch): bool
    {
        return $this->isSuperAdmin($admin);
    }

    public function makeDefault(Admin $admin, Branch $branch): bool
    {
        return $this->isSuperAdmin($admin);
    }
}
