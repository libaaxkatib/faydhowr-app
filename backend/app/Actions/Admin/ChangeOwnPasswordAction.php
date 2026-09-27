<?php

namespace App\Actions\Admin;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ChangeOwnPasswordAction
{
    /**
     * @param  array{current_password: string, password: string}  $data
     */
    public function handle(Admin $admin, array $data, ?string $currentTokenId): void
    {
        if (! Hash::check($data['current_password'], $admin->password)) {
            throw new DomainException('CURRENT_PASSWORD_INCORRECT');
        }

        DB::transaction(function () use ($admin, $data, $currentTokenId): void {
            $admin = Admin::query()
                ->whereKey($admin)
                ->lockForUpdate()
                ->firstOrFail();

            $admin->forceFill(['password' => $data['password']])->save();

            // Revoke every other session/device but keep the one the admin is
            // currently using, so changing your own password doesn't log you
            // out mid-action - matches LogoutAdminAction's existing per-token
            // (not global) revocation model.
            $admin->tokens()
                ->when($currentTokenId, fn ($query) => $query->where('id', '!=', $currentTokenId))
                ->delete();
        });

        event(AuditEvent::record(
            action: AuditAction::PasswordChange,
            admin: $admin,
            description: 'Admin changed their own password.',
            entityType: Admin::class,
            entityId: $admin->id,
        ));
    }
}
