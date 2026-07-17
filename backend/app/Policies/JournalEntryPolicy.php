<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\JournalEntry;
use App\Policies\Concerns\AuthorizesAccountingAccess;

class JournalEntryPolicy
{
    use AuthorizesAccountingAccess;

    public function viewAny(Admin $admin): bool
    {
        return $this->canViewAccounting($admin);
    }

    public function view(Admin $admin, JournalEntry $journalEntry): bool
    {
        return $this->canViewAccounting($admin);
    }

    public function post(Admin $admin, JournalEntry $journalEntry): bool
    {
        return $this->canViewAccounting($admin);
    }
}
