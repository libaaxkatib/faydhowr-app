<?php

namespace App\Policies\Concerns;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Support\AdminPermissionResolver;

trait AuthorizesCustomerAccess
{
    public function __construct(private AdminPermissionResolver $permissions) {}

    protected function canViewCustomers(Admin $admin): bool
    {
        return $this->permissions->has($admin, AdminPermission::CustomersView->value);
    }

    protected function canCreateCustomers(Admin $admin): bool
    {
        return $this->permissions->has($admin, AdminPermission::CustomersCreate->value);
    }

    protected function canUpdateCustomers(Admin $admin): bool
    {
        return $this->permissions->has($admin, AdminPermission::CustomersUpdate->value);
    }

    protected function canDeleteCustomers(Admin $admin): bool
    {
        return $this->permissions->has($admin, AdminPermission::CustomersDelete->value);
    }

    protected function canRestoreCustomers(Admin $admin): bool
    {
        return $admin->role === AdminRole::SuperAdmin
            && $this->permissions->has($admin, AdminPermission::CustomersRestore->value);
    }

    protected function canManageNotes(Admin $admin): bool
    {
        return $this->permissions->has($admin, AdminPermission::CustomersNotes->value);
    }

    protected function canManageAttachments(Admin $admin): bool
    {
        return $this->permissions->has($admin, AdminPermission::CustomersAttachments->value);
    }
}
