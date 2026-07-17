<?php

namespace App\Policies;

use App\Models\Admin;
use App\Policies\Concerns\AuthorizesAccountingAccess;

class AccountingPeriodPolicy
{
    use AuthorizesAccountingAccess;

    public function viewAny(Admin $admin): bool
    {
        return $this->canViewAccounting($admin);
    }

    public function create(Admin $admin): bool
    {
        return $this->canViewAccounting($admin);
    }
}
