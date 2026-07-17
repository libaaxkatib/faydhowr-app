<?php

namespace App\Policies\Concerns;

use App\Enums\AdminPermission;
use App\Models\Admin;
use App\Support\AdminPermissionResolver;

/**
 * Shared check for the Accounting module policies: every accounting
 * ability requires the accounting.view permission, resolved through the
 * same hybrid role/direct permission resolver the middleware uses.
 */
trait AuthorizesAccountingAccess
{
    public function __construct(private AdminPermissionResolver $permissions) {}

    protected function canViewAccounting(Admin $admin): bool
    {
        return $this->permissions->has($admin, AdminPermission::AccountingView->value);
    }
}
