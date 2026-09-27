<?php

namespace App\Actions\Admin;

use App\Enums\AdminRole;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Only Super Admin may reset another admin's password (mirrors the actor
 * check already used by UpdateAdminAction/DeleteAdminAction). The new
 * password is supplied by the Super Admin and validated the same way as
 * account creation - it is never generated or returned by this action, so no
 * plaintext temporary password ever crosses the API.
 */
class ResetAdminPasswordAction
{
    /**
     * @param  array{password: string}  $data
     */
    public function handle(Admin $actor, Admin $target, array $data): void
    {
        if ($actor->role !== AdminRole::SuperAdmin) {
            throw new DomainException('FORBIDDEN');
        }

        DB::transaction(function () use ($target, $data): void {
            $target = Admin::query()
                ->whereKey($target)
                ->lockForUpdate()
                ->firstOrFail();

            $target->forceFill(['password' => $data['password']])->save();

            // A Super-Admin-initiated reset is typically a lockout/compromise
            // response, so every existing session for the target is revoked -
            // unlike a self-service change, there is no "current session" of
            // the target's to preserve here.
            $target->tokens()->delete();
        });

        event(AuditEvent::record(
            action: AuditAction::PasswordReset,
            admin: $actor,
            description: 'Admin password reset by Super Admin.',
            entityType: Admin::class,
            entityId: $target->id,
            metadata: [
                'target_admin_id' => $target->id,
            ],
        ));
    }
}
