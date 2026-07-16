<?php

namespace App\Actions\Admin;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;

class LogoutAdminAction
{
    public function handle(Admin $admin): void
    {
        $admin->currentAccessToken()?->delete();

        event(AuditEvent::record(
            action: AuditAction::Logout,
            admin: $admin,
            description: 'Admin logged out.',
            entityType: Admin::class,
            entityId: $admin->id,
        ));
    }
}
