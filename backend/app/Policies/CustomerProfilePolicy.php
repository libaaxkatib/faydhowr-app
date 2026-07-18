<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\CustomerProfile;
use App\Policies\Concerns\AuthorizesCustomerAccess;

class CustomerProfilePolicy
{
    use AuthorizesCustomerAccess;

    public function viewAny(Admin $admin): bool
    {
        return $this->canViewCustomers($admin);
    }

    public function view(Admin $admin, CustomerProfile $customer): bool
    {
        return $this->canViewCustomers($admin);
    }

    public function create(Admin $admin): bool
    {
        return $this->canCreateCustomers($admin);
    }

    public function update(Admin $admin, CustomerProfile $customer): bool
    {
        return $this->canUpdateCustomers($admin);
    }

    public function updateStatus(Admin $admin, CustomerProfile $customer): bool
    {
        return $this->canUpdateCustomers($admin);
    }

    public function delete(Admin $admin, CustomerProfile $customer): bool
    {
        return $this->canDeleteCustomers($admin);
    }

    public function restore(Admin $admin, CustomerProfile $customer): bool
    {
        return $this->canRestoreCustomers($admin);
    }

    public function manageNotes(Admin $admin, CustomerProfile $customer): bool
    {
        return $this->canManageNotes($admin);
    }

    public function manageAttachments(Admin $admin, CustomerProfile $customer): bool
    {
        return $this->canManageAttachments($admin);
    }
}
