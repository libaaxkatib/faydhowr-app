<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\Admin;
use App\Policies\Concerns\AuthorizesAccountingAccess;

class AccountPolicy
{
    use AuthorizesAccountingAccess;

    public function viewAny(Admin $admin): bool
    {
        return $this->canViewAccounting($admin);
    }

    public function view(Admin $admin, Account $account): bool
    {
        return $this->canViewAccounting($admin);
    }
}
